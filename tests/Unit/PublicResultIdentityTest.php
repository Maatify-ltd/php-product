<?php

declare(strict_types=1);

namespace Maatify\Product\Tests\Unit;

use DateTimeImmutable;
use Maatify\Product\Common\Exception\ProductInvalidArgumentException;
use Maatify\Product\Content\DTO\CreatedOptionContentDTO;
use Maatify\Product\Content\DTO\CreatedOptionValueContentDTO;
use Maatify\Product\Content\DTO\CreatedProductContentDTO;
use Maatify\Product\ImageAssignment\DTO\CreatedImageAssignmentDTO;
use Maatify\Product\ImageAssignment\DTO\ImageAssignmentDTO;
use Maatify\Product\ImageRole\DTO\CreatedImageRoleDTO;
use Maatify\Product\Option\DTO\CreatedOptionDTO;
use Maatify\Product\OptionValue\DTO\CreatedOptionValueDTO;
use Maatify\Product\Product\DTO\CreatedProductDTO;
use Maatify\Product\Product\DTO\ProductReconfigurationResultDTO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PublicResultIdentityTest extends TestCase
{
    /** @return iterable<string, array{callable(): object}> */
    public static function invalidIdentities(): iterable
    {
        foreach ([0, -1] as $id) {
            $now = new DateTimeImmutable();
            yield "product $id" => [static fn() => new CreatedProductDTO($id, 1)];
            yield "variant $id" => [static fn() => new CreatedProductDTO(1, $id)];
            yield "option $id" => [static fn() => new CreatedOptionDTO($id)];
            yield "value $id" => [static fn() => new CreatedOptionValueDTO($id)];
            yield "role $id" => [static fn() => new CreatedImageRoleDTO($id)];
            yield "assignment $id" => [static fn() => new CreatedImageAssignmentDTO($id)];
            yield "product content $id" => [static fn() => new CreatedProductContentDTO($id)];
            yield "option content $id" => [static fn() => new CreatedOptionContentDTO($id)];
            yield "value content $id" => [static fn() => new CreatedOptionValueContentDTO($id)];
            yield "assignment variant $id" => [static fn() => new ImageAssignmentDTO(1, 1, $id, 1, null, null, null, false, 1, $now, $now, null)];
            yield "assignment role $id" => [static fn() => new ImageAssignmentDTO(1, 1, null, 1, $id, null, null, false, 1, $now, $now, null)];
            for ($index = 0; $index < 8; ++$index) {
                $lists = array_fill(0, 8, []);
                $lists[$index] = [$id];
                yield "reconfiguration list $index identity $id" => [static fn() => new ProductReconfigurationResultDTO(...$lists)];
            }
        }
        foreach (['float' => 1.5, 'numeric string' => '2', 'boolean' => true] as $type => $id) {
            for ($index = 0; $index < 8; ++$index) {
                $lists = array_fill(0, 8, []);
                $lists[$index] = [$id];
                yield "reconfiguration list $index rejects $type" => [
                    static fn() => (new ReflectionClass(ProductReconfigurationResultDTO::class))->newInstanceArgs($lists),
                ];
            }
        }
    }

    /** @param callable(): object $construct */
    #[DataProvider('invalidIdentities')]
    public function testPublicResultsRejectInvalidIdentities(callable $construct): void
    {
        $this->expectException(ProductInvalidArgumentException::class);
        $construct();
    }
}
