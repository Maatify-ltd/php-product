-- Upgrade the schema installed from Product v1.0.0-rc.1. See schema/README.md.

ALTER TABLE `maa_product_product_contents`
    ADD COLUMN `language_code_is_null` TINYINT(1) GENERATED ALWAYS AS (`language_code` IS NULL) STORED
        COMMENT 'Distinguishes the NULL language from every non-NULL language' AFTER `language_code`,
    DROP INDEX `uq_maa_product_product_contents_identity`,
    ADD UNIQUE KEY `uq_maa_product_product_contents_identity`
        (`product_id`, `language_code_is_null`, `language_code_identity`);

ALTER TABLE `maa_product_option_contents`
    ADD COLUMN `language_code_is_null` TINYINT(1) GENERATED ALWAYS AS (`language_code` IS NULL) STORED
        COMMENT 'Distinguishes the NULL language from every non-NULL language' AFTER `language_code`,
    DROP INDEX `uq_maa_product_option_contents_identity`,
    ADD UNIQUE KEY `uq_maa_product_option_contents_identity`
        (`option_id`, `language_code_is_null`, `language_code_identity`);

ALTER TABLE `maa_product_option_value_contents`
    ADD COLUMN `language_code_is_null` TINYINT(1) GENERATED ALWAYS AS (`language_code` IS NULL) STORED
        COMMENT 'Distinguishes the NULL language from every non-NULL language' AFTER `language_code`,
    DROP INDEX `uq_maa_product_option_value_contents_identity`,
    ADD UNIQUE KEY `uq_maa_product_option_value_contents_identity`
        (`option_value_id`, `language_code_is_null`, `language_code_identity`);

ALTER TABLE `maa_product_image_assignments`
    ADD COLUMN `language_code_is_null` TINYINT(1) GENERATED ALWAYS AS (`language_code` IS NULL) STORED
        COMMENT 'Distinguishes the NULL language from every non-NULL language' AFTER `role_id_identity`,
    ADD COLUMN `platform_is_null` TINYINT(1) GENERATED ALWAYS AS (`platform` IS NULL) STORED
        COMMENT 'Distinguishes the NULL platform from every non-NULL platform' AFTER `language_code_identity`,
    DROP INDEX `uq_maa_product_image_assignments_identity`,
    ADD UNIQUE KEY `uq_maa_product_image_assignments_identity`
        (`product_id`, `variant_id_identity`, `media_asset_id`, `role_id_identity`,
         `language_code_is_null`, `language_code_identity`, `platform_is_null`, `platform_identity`);
