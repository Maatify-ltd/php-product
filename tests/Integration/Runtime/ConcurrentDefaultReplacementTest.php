<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Integration\Runtime;

use Maatify\Product\ImageAssignment\Management\Command\CreateImageAssignmentCommand;

/**
 * Real concurrent default replacements within one exact scope: separate processes and connections
 * released at once. The target-scope lock and the database unique key leave exactly one default,
 * and restore-as-default never replaces the default another writer set first.
 */
final class ConcurrentDefaultReplacementTest extends RuntimeTestCase
{
    use ConcurrentWorkers;

    private const int WORKERS = 8;

    public function testConcurrentSetDefaultWithinOneScopeLeavesExactlyOneDefault(): void
    {
        $created = $this->namedProduct('DEFAULTS');
        $service = $this->imageAssignmentManagementService();
        $commands = [];
        for ($i = 1; $i <= self::WORKERS; ++$i) {
            $assignmentId = $service->create(new CreateImageAssignmentCommand($created->productId, null, $i))->assignmentId;
            $commands[] = ['set-default', ['assignmentId' => $assignmentId]];
        }

        $outcomes = $this->runCommandWorkers($commands);

        foreach ($outcomes as $outcome) {
            self::assertStringStartsWith('ok:', $outcome, implode(' | ', $outcomes));
        }
        self::assertSame(1, self::rowCount('maa_product_image_assignments', 'is_default = 1'), implode(' | ', $outcomes));
        self::assertSame(self::WORKERS, self::rowCount('maa_product_image_assignments', 'deleted_at IS NULL'));
    }

    public function testConcurrentRestoresAsDefaultWithinOneScopeYieldOneWinnerAndOneConflict(): void
    {
        $created = $this->namedProduct('RESTORE-DEFAULTS');
        $service = $this->imageAssignmentManagementService();
        $first = $service->create(new CreateImageAssignmentCommand($created->productId, null, 1))->assignmentId;
        $second = $service->create(new CreateImageAssignmentCommand($created->productId, null, 2))->assignmentId;
        $service->softDelete($first);
        $service->softDelete($second);

        $outcomes = $this->runCommandWorkers([
            ['restore-as-default', ['assignmentId' => $first]],
            ['restore-as-default', ['assignmentId' => $second]],
        ]);

        $summary = implode(' | ', $outcomes);
        $winners = array_values(array_filter($outcomes, static fn(string $outcome): bool => str_starts_with($outcome, 'ok:')));
        self::assertCount(1, $winners, $summary);
        self::assertSame(1, count(array_filter($outcomes, static fn(string $outcome): bool => $outcome === 'conflict')), $summary);
        $winner = (int) substr($winners[0], 3);
        $loser = $winner === $first ? $second : $first;
        self::assertSame(1, self::rowCount('maa_product_image_assignments', 'is_default = 1'), $summary);
        self::assertSame(1, self::rowCount('maa_product_image_assignments', 'is_default = 1 AND deleted_at IS NULL AND id = ' . $winner), $summary);
        self::assertSame(1, self::rowCount('maa_product_image_assignments', 'is_default = 0 AND deleted_at IS NOT NULL AND id = ' . $loser), 'The loser stays soft deleted and non-default.');
    }
}
