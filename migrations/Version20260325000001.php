<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260325000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Full schema: UUID PKs (users, accounts, ledger_entries, audit_logs), IFSC on accounts, structured audit_logs';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('
            CREATE TABLE users (
                id         CHAR(36)         NOT NULL,
                email      VARCHAR(180)     NOT NULL,
                name       VARCHAR(255)     NOT NULL,
                password   VARCHAR(255)     NOT NULL,
                roles      JSON             NOT NULL,
                status     VARCHAR(32)      NOT NULL DEFAULT \'ACTIVE\',
                created_at DATETIME         NOT NULL,
                UNIQUE INDEX UNIQ_users_email (email),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        ');

        $this->addSql('
            CREATE TABLE accounts (
                id             CHAR(36)                          NOT NULL,
                user_id        CHAR(36)                          NOT NULL,
                account_number VARCHAR(13)                       NOT NULL,
                account_type   VARCHAR(255)                      NOT NULL,
                balance        NUMERIC(15, 2) DEFAULT \'0.00\'    NOT NULL,
                currency       VARCHAR(3)     DEFAULT \'INR\'     NOT NULL,
                ifsc_code        VARCHAR(11)    DEFAULT NULL,
                beneficiary_name VARCHAR(255) NOT NULL DEFAULT \'\',
                is_blocked       TINYINT(1)     DEFAULT 0           NOT NULL,
                created_at     DATETIME                          NOT NULL,
                INDEX  idx_account_user_id  (user_id),
                UNIQUE INDEX UNIQ_account_number (account_number),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        ');

        $this->addSql('
            CREATE TABLE transactions (
                id                        CHAR(36)       NOT NULL,
                from_account_id           CHAR(36)       NOT NULL,
                to_account_id             CHAR(36)       NOT NULL,
                amount                    NUMERIC(15, 2) NOT NULL,
                status                    VARCHAR(255)   NOT NULL,
                failure_reason            VARCHAR(512)   DEFAULT NULL,
                mode                      VARCHAR(255)   NOT NULL,
                idempotency_key           VARCHAR(255)   DEFAULT NULL,
                reference_transaction_id  CHAR(36)       DEFAULT NULL,
                is_reversal               TINYINT(1)     DEFAULT 0 NOT NULL,
                reversal_reason           VARCHAR(512)   DEFAULT NULL,
                created_at                DATETIME       NOT NULL,
                INDEX  idx_transaction_from_account  (from_account_id),
                INDEX  idx_transaction_to_account    (to_account_id),
                INDEX  idx_transaction_status        (status),
                INDEX  IDX_EAA81A4C76500E2D            (reference_transaction_id),
                UNIQUE INDEX UNIQ_transaction_idempotency_key (idempotency_key),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        ');

        $this->addSql('
            CREATE TABLE ledger_entries (
                id             CHAR(36)       NOT NULL,
                account_id     CHAR(36)       NOT NULL,
                transaction_id CHAR(36)       NOT NULL,
                type           VARCHAR(255)   NOT NULL,
                amount         NUMERIC(15, 2) NOT NULL,
                balance_after  NUMERIC(15, 2) NOT NULL,
                created_at     DATETIME       NOT NULL,
                INDEX idx_ledger_account_id       (account_id),
                INDEX idx_ledger_transaction_id   (transaction_id),
                INDEX idx_ledger_account_timeline (account_id, created_at),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        ');

        $this->addSql('
            CREATE TABLE audit_logs (
                id              CHAR(36)       NOT NULL,
                event           VARCHAR(100)   NOT NULL,
                entity_type     VARCHAR(100)   DEFAULT NULL,
                entity_id       CHAR(36)       DEFAULT NULL,
                transaction_id  CHAR(36)       DEFAULT NULL,
                from_account_id CHAR(36)       DEFAULT NULL,
                to_account_id   CHAR(36)       DEFAULT NULL,
                amount          NUMERIC(15, 2) DEFAULT NULL,
                context         JSON           DEFAULT NULL,
                user_id         CHAR(36)       DEFAULT NULL,
                ip_address      VARCHAR(45)    DEFAULT NULL,
                created_at      DATETIME       NOT NULL,
                INDEX idx_audit_event       (event),
                INDEX idx_audit_transaction (transaction_id),
                INDEX idx_audit_created_at  (created_at),
                INDEX idx_audit_entity      (entity_type, entity_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci`
        ');

        $this->addSql('ALTER TABLE accounts       ADD CONSTRAINT FK_CAC89EACA76ED395 FOREIGN KEY (user_id)                   REFERENCES users        (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE transactions   ADD CONSTRAINT FK_EAA81A4CB0CF99BD FOREIGN KEY (from_account_id)           REFERENCES accounts     (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transactions   ADD CONSTRAINT FK_EAA81A4CBC58BDC7 FOREIGN KEY (to_account_id)             REFERENCES accounts     (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE transactions   ADD CONSTRAINT FK_EAA81A4C76500E2D FOREIGN KEY (reference_transaction_id)    REFERENCES transactions (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ledger_entries ADD CONSTRAINT FK_E3FD73F49B6B5FBA FOREIGN KEY (account_id)                REFERENCES accounts     (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE ledger_entries ADD CONSTRAINT FK_E3FD73F42FC0CB0F FOREIGN KEY (transaction_id)            REFERENCES transactions (id) ON DELETE RESTRICT');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ledger_entries DROP FOREIGN KEY FK_E3FD73F42FC0CB0F');
        $this->addSql('ALTER TABLE ledger_entries DROP FOREIGN KEY FK_E3FD73F49B6B5FBA');
        $this->addSql('ALTER TABLE transactions   DROP FOREIGN KEY FK_EAA81A4C76500E2D');
        $this->addSql('ALTER TABLE transactions   DROP FOREIGN KEY FK_EAA81A4CBC58BDC7');
        $this->addSql('ALTER TABLE transactions   DROP FOREIGN KEY FK_EAA81A4CB0CF99BD');
        $this->addSql('ALTER TABLE accounts       DROP FOREIGN KEY FK_CAC89EACA76ED395');
        $this->addSql('DROP TABLE ledger_entries');
        $this->addSql('DROP TABLE audit_logs');
        $this->addSql('DROP TABLE transactions');
        $this->addSql('DROP TABLE accounts');
        $this->addSql('DROP TABLE users');
    }
}
