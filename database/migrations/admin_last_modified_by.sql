ALTER TABLE `admins`
    ADD COLUMN `last_modified_by` INT UNSIGNED DEFAULT NULL
        COMMENT 'Admin ID who most recently added or edited this record'
        AFTER `added_by`,
    ADD CONSTRAINT `fk_admin_last_modified_by`
        FOREIGN KEY (`last_modified_by`) REFERENCES `admins`(`id`) ON DELETE SET NULL;