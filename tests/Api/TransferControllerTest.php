<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Entity\Account;
use App\Entity\AuditLog;
use App\Entity\LedgerEntry;
use App\Entity\Transaction;
use App\Entity\User;
use App\Enum\AccountType;
use App\Enum\LedgerEntryType;
use App\Enum\TransactionStatus;
use App\Enum\UserStatus;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Full-stack integration tests for POST /api/v1/transfer,
 * GET /api/v1/transfer/audit-logs, and GET /api/v1/audit/{entityType}/{entityId}.
 *
 * Every response follows the unified API envelope:
 *
 *   Success: { "success": true,  "data": { "type": "transfer", "attributes": { ... } } }
 *   Error:   { "success": false, "message": "...", "errors"?: { ... } }
 *
 * Tests are grouped by concern:
 *   1. Input Validation (400)
 *   2. Business Rules (422)
 *   3. Success / Edge Cases (201)
 *   4. Idempotency
 *   5. Database Side-Effects (ledger, audit log)
 *   6. Sequential Transfers
 *   7. Global Audit-Log Endpoint (transfer/audit-logs)
 *   8. Entity-Scoped Audit Trail (/audit/{entityType}/{entityId})
 *   9. Protocol
 */
final class TransferControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    // ── Fixture account numbers (ACC + 10 uppercase hex chars) ────────────────
    private const ACC_ALICE   = 'ACCAA00000001'; // SAVINGS  1 000.00  active
    private const ACC_BOB     = 'ACCBB00000002'; // CURRENT    500.00  active
    private const ACC_CHARLIE = 'ACCCC00000003'; // SAVINGS    300.00  BLOCKED
    private const ACC_DAVE    = 'ACCDD00000004'; // SAVINGS 60 000.00  active  (limit testing)
    private const ACC_EVE     = 'ACCEE00000005'; // CURRENT 200 000.00 active  (fraud testing)

    /** Valid UUID not present in seeded data — used for “account not found” cases. */
    private const UNKNOWN_ACCOUNT_UUID = '00000000-0000-0000-0000-000000000099';

    private const IFSC_ALICE   = 'HDFC0001234';
    private const IFSC_BOB     = 'SBIN0001234';
    private const IFSC_CHARLIE = 'ICIC0001234';
    private const IFSC_DAVE    = 'AXIS0001234';
    private const IFSC_EVE     = 'YESB0001234';

    private const NAME_ALICE   = 'Alice Holder';
    private const NAME_BOB     = 'Bob Holder';
    private const NAME_CHARLIE = 'Charlie Holder';
    private const NAME_DAVE    = 'Dave Holder';
    private const NAME_EVE     = 'Eve Holder';

    // ── Lifecycle ─────────────────────────────────────────────────────────────

    public static function setUpBeforeClass(): void
    {
        // Clear Symfony test cache so Doctrine ORM sees current entity mappings (UUID PKs, etc.).
        $cacheDir = dirname(__DIR__, 2) . '/var/cache/test';
        if (is_dir($cacheDir)) {
            (new Filesystem())->remove($cacheDir);
        }

        static::bootKernel();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $schemaTool = new SchemaTool($em);
        $metadata   = $em->getMetadataFactory()->getAllMetadata();

        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

        static::ensureKernelShutdown();
    }

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em     = static::getContainer()->get(EntityManagerInterface::class);

        $this->truncateTables();
        $this->seedAccounts();
    }

    // =========================================================================
    // 1. Input Validation (400)
    // =========================================================================

    /**
     * A blank from_account_id must produce a field-level validation error.
     */
    public function testMissingFromAccountIdReturns400(): void
    {
        $this->postJson('/api/v1/transfer', [
            'to_account_id' => $this->accountId(self::ACC_BOB),
            'amount'        => '100.00',
        ]);

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertSame('Validation failed.', $body['message']);
        $this->assertArrayHasKey('from_account_id', $body['errors']);
    }

    /**
     * A blank to_account_id must produce a field-level validation error.
     */
    public function testMissingToAccountIdReturns400(): void
    {
        $this->postJson('/api/v1/transfer', [
            'from_account_id' => $this->accountId(self::ACC_ALICE),
            'amount'          => '100.00',
        ]);

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('to_account_id', $body['errors']);
    }

    /**
     * A blank amount must produce a field-level validation error.
     */
    public function testMissingAmountReturns400(): void
    {
        $b = $this->transferBody();
        unset($b['amount']);
        $this->postJson('/api/v1/transfer', $b);

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('amount', $body['errors']);
    }

    /**
     * An empty body must return errors for all required fields simultaneously.
     */
    public function testEmptyBodyReturnsAllFieldErrors(): void
    {
        $this->postJson('/api/v1/transfer', []);

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('from_account_id', $body['errors']);
        $this->assertArrayHasKey('to_account_id',   $body['errors']);
        $this->assertArrayHasKey('amount',          $body['errors']);
    }

    /**
     * An amount of "0.00" must fail — zero transfers are not permitted.
     */
    public function testZeroAmountReturns400(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '0.00']));

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('amount', $body['errors']);
        $this->assertStringContainsStringIgnoringCase('greater than 0', $body['errors']['amount']);
    }

    /**
     * A negative amount must fail — the validator rejects non-positive decimals.
     */
    public function testNegativeAmountReturns400(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '-50.00']));

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('amount', $body['errors']);
    }

    /**
     * Three decimal places must fail — only up to two decimal places are allowed.
     */
    public function testAmountWithTooManyDecimalsReturns400(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '100.001']));

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('amount', $body['errors']);
    }

    /**
     * A non-numeric amount string must fail validation.
     */
    public function testNonNumericAmountReturns400(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => 'one-hundred']));

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('amount', $body['errors']);
    }

    /**
     * A sender id that is not a valid UUID must fail validation.
     */
    public function testInvalidFromAccountIdFormatReturns400(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody([
            'from_account_id' => 'not-a-uuid',
        ]));

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('from_account_id', $body['errors']);
    }

    /**
     * A completely malformed JSON body must return validation errors rather
     * than a 500 — the controller defaults to an empty array on parse failure.
     */
    public function testMalformedJsonBodyReturns400(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/transfer',
            [], [], ['CONTENT_TYPE' => 'application/json'],
            '{not: valid json',
        );

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertArrayHasKey('errors', $body);
    }

    // =========================================================================
    // 2. Business Rules (422)
    // =========================================================================

    /**
     * A valid UUID that does not match any account (from) must return 422.
     */
    public function testNonExistentFromAccountReturns422(): void
    {
        $this->postJson('/api/v1/transfer', [
            'from_account_id' => self::UNKNOWN_ACCOUNT_UUID,
            'to_account_id'   => $this->accountId(self::ACC_BOB),
            'amount'          => '100.00',
        ]);

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $this->assertErrorEnvelope($body, 'not found');
    }

    /**
     * A valid UUID that does not match any account (to) must return 422.
     */
    public function testNonExistentToAccountReturns422(): void
    {
        $this->postJson('/api/v1/transfer', [
            'from_account_id' => $this->accountId(self::ACC_ALICE),
            'to_account_id'   => self::UNKNOWN_ACCOUNT_UUID,
            'amount'          => '100.00',
        ]);

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $this->assertErrorEnvelope($body, 'not found');
    }

    /**
     * A transfer FROM a blocked account must return 422 with a "blocked" message.
     */
    public function testBlockedSenderReturns422(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBodyFor(self::ACC_CHARLIE, self::ACC_BOB, '100.00'));

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $this->assertErrorEnvelope($body, 'blocked');

        [$charlie, $bob] = $this->reloadAccounts(self::ACC_CHARLIE, self::ACC_BOB);
        $this->assertSame('300.00',  $charlie->getBalance(), 'Charlie balance unchanged');
        $this->assertSame('500.00',  $bob->getBalance(),     'Bob balance unchanged');
        $this->assertCount(0, $this->em->getRepository(Transaction::class)->findAll());
    }

    /**
     * A transfer TO a blocked account must return 422 with a "blocked" message.
     */
    public function testBlockedReceiverReturns422(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBodyFor(self::ACC_ALICE, self::ACC_CHARLIE, '100.00'));

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $this->assertErrorEnvelope($body, 'blocked');

        [$alice, $charlie] = $this->reloadAccounts(self::ACC_ALICE, self::ACC_CHARLIE);
        $this->assertSame('1000.00', $alice->getBalance(),   'Alice balance unchanged');
        $this->assertSame('300.00',  $charlie->getBalance(), 'Charlie balance unchanged');
        $this->assertCount(0, $this->em->getRepository(Transaction::class)->findAll());
    }

    /**
     * A transfer FROM an account whose owner user is BLOCKED must return 422.
     */
    public function testBlockedSenderUserReturns422(): void
    {
        $this->em->clear();
        $eveAccount = $this->em->getRepository(Account::class)->findOneBy(['accountNumber' => self::ACC_EVE]);
        $this->assertNotNull($eveAccount);
        $eveAccount->getUser()->setStatus(UserStatus::BLOCKED);
        $this->em->flush();

        $this->postJson('/api/v1/transfer', $this->transferBodyFor(self::ACC_EVE, self::ACC_BOB, '100.00'));

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $this->assertErrorEnvelope($body, 'blocked');

        [$eve, $bob] = $this->reloadAccounts(self::ACC_EVE, self::ACC_BOB);
        $this->assertSame('200000.00', $eve->getBalance(), 'Eve balance unchanged');
        $this->assertSame('500.00',    $bob->getBalance(), 'Bob balance unchanged');
        $this->assertCount(0, $this->em->getRepository(Transaction::class)->findAll());
    }

    /**
     * Transferring more than the available balance must return 422.
     */
    public function testInsufficientBalanceReturns422(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '9999.00']));

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $this->assertErrorEnvelope($body, 'insufficient');

        [$alice, $bob] = $this->reloadAccounts(self::ACC_ALICE, self::ACC_BOB);
        $this->assertSame('1000.00', $alice->getBalance());
        $this->assertSame('500.00',  $bob->getBalance());
        $this->assertCount(0, $this->em->getRepository(Transaction::class)->findAll());
    }

    /**
     * Specifying the same account for both sender and receiver must return 400
     * (caught at the validator layer, before any service is involved).
     */
    public function testSameAccountTransferReturns400(): void
    {
        $aliceId = $this->accountId(self::ACC_ALICE);
        $this->postJson('/api/v1/transfer', [
            'from_account_id' => $aliceId,
            'to_account_id'   => $aliceId,
            'amount'          => '50.00',
        ]);

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
        $this->assertFalse($body['success']);
        $this->assertSame('Validation failed.', $body['message']);
        $this->assertArrayHasKey('to_account_id', $body['errors']);

        [$alice] = $this->reloadAccounts(self::ACC_ALICE);
        $this->assertSame('1000.00', $alice->getBalance());
        $this->assertCount(0, $this->em->getRepository(Transaction::class)->findAll());
    }

    /**
     * A SAVINGS account has a 50 000.00 per-transaction cap.
     * Dave (SAVINGS, 60 000.00) attempting 50 001.00 must hit the TransferLimitRule.
     */
    public function testTransferLimitExceededReturns422(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBodyFor(self::ACC_DAVE, self::ACC_BOB, '50001.00'));

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $this->assertErrorEnvelope($body, 'limit');

        [$dave] = $this->reloadAccounts(self::ACC_DAVE);
        $this->assertSame('60000.00', $dave->getBalance(), 'Dave balance must remain unchanged');
        $this->assertCount(0, $this->em->getRepository(Transaction::class)->findAll());
    }

    /**
     * Any single transfer over 100 000.00 triggers the fraud-detection rule.
     * Eve (CURRENT, 200 000.00) attempting 100 001.00 must be rejected with a
     * fraud alert before reaching the TransferLimitRule.
     */
    public function testFraudDetectionTriggeredReturns422(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBodyFor(self::ACC_EVE, self::ACC_BOB, '100001.00'));

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $this->assertErrorEnvelope($body, 'fraud');

        [$eve] = $this->reloadAccounts(self::ACC_EVE);
        $this->assertSame('200000.00', $eve->getBalance(), 'Eve balance must remain unchanged');
        $this->assertCount(0, $this->em->getRepository(Transaction::class)->findAll());
    }

    // =========================================================================
    // 3. Successful Transfer / Edge Cases (201)
    // =========================================================================

    /**
     * Happy path: balances updated, transaction persisted, correct envelope returned.
     */
    public function testSuccessfulTransfer(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '200.00']));

        $response = $this->client->getResponse();
        $body     = $this->decodeJson($response);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $attrs = $this->assertSuccessEnvelope($body, 'transfer');

        $this->assertSame('SUCCESS', $attrs['status']);
        $this->assertNotEmpty($attrs['transactionId']);
        $this->assertNull($attrs['idempotencyKey']);

        [$alice, $bob] = $this->reloadAccounts(self::ACC_ALICE, self::ACC_BOB);
        $this->assertSame('800.00', $alice->getBalance(), 'Alice debited 200.00');
        $this->assertSame('700.00', $bob->getBalance(),   'Bob credited 200.00');

        /** @var Transaction $txn */
        $txn = $this->em->find(Transaction::class, $attrs['transactionId']);
        $this->assertNotNull($txn);
        $this->assertSame(TransactionStatus::SUCCESS, $txn->getStatus());
        $this->assertSame('200.00', $txn->getAmount());
    }

    /**
     * Transferring the sender's exact balance (all remaining funds) must succeed.
     * Edge case: bcmath comparison must treat equal values as sufficient.
     */
    public function testTransferOfExactBalanceSucceeds(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '1000.00']));

        $response = $this->client->getResponse();
        $body     = $this->decodeJson($response);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertSuccessEnvelope($body, 'transfer');

        [$alice, $bob] = $this->reloadAccounts(self::ACC_ALICE, self::ACC_BOB);
        $this->assertSame('0.00',    $alice->getBalance(), 'Alice balance should be zero');
        $this->assertSame('1500.00', $bob->getBalance(),   'Bob should have 500 + 1000');
    }

    /**
     * The smallest valid decimal amount (0.01) must be accepted and applied precisely.
     */
    public function testMinimumAmountTransferSucceeds(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '0.01']));

        $response = $this->client->getResponse();
        $body     = $this->decodeJson($response);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertSuccessEnvelope($body, 'transfer');

        [$alice, $bob] = $this->reloadAccounts(self::ACC_ALICE, self::ACC_BOB);
        $this->assertSame('999.99', $alice->getBalance());
        $this->assertSame('500.01', $bob->getBalance());
    }

    /**
     * The SAVINGS limit (50 000.00) is a strict ceiling — transferring exactly
     * the limit amount must succeed.
     */
    public function testTransferAtExactLimitSucceeds(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBodyFor(self::ACC_DAVE, self::ACC_BOB, '50000.00'));

        $response = $this->client->getResponse();
        $body     = $this->decodeJson($response);

        $this->assertSame(Response::HTTP_CREATED, $response->getStatusCode());
        $this->assertSuccessEnvelope($body, 'transfer');

        [$dave] = $this->reloadAccounts(self::ACC_DAVE);
        $this->assertSame('10000.00', $dave->getBalance());
    }

    // =========================================================================
    // 4. Idempotency
    // =========================================================================

    /**
     * Replaying the same Idempotency-Key must:
     *  - return the identical transactionId
     *  - debit the sender only once
     *  - leave exactly one Transaction row in the database
     */
    public function testIdempotentTransferDeductsBalanceOnlyOnce(): void
    {
        $key = 'idem-test-' . bin2hex(random_bytes(4));

        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '100.00']), $key);

        $firstBody  = $this->decodeJson($this->client->getResponse());
        $firstAttrs = $this->assertSuccessEnvelope($firstBody, 'transfer');
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '100.00']), $key);

        $secondBody  = $this->decodeJson($this->client->getResponse());
        $secondAttrs = $this->assertSuccessEnvelope($secondBody, 'transfer');

        $this->assertSame(
            $firstAttrs['transactionId'],
            $secondAttrs['transactionId'],
            'Replay must return the same transactionId.',
        );

        [$alice] = $this->reloadAccounts(self::ACC_ALICE);
        $this->assertSame('900.00', $alice->getBalance(), 'Alice debited only once');
        $this->assertCount(1, $this->em->getRepository(Transaction::class)->findAll());
    }

    /**
     * Two requests with different Idempotency-Keys must create two separate
     * transactions and debit the sender twice.
     */
    public function testDifferentIdempotencyKeysCreateSeparateTransactions(): void
    {
        $key1 = 'idem-a-' . bin2hex(random_bytes(4));
        $key2 = 'idem-b-' . bin2hex(random_bytes(4));

        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '100.00']), $key1);

        $body1  = $this->decodeJson($this->client->getResponse());
        $attrs1 = $this->assertSuccessEnvelope($body1, 'transfer');

        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '100.00']), $key2);

        $body2  = $this->decodeJson($this->client->getResponse());
        $attrs2 = $this->assertSuccessEnvelope($body2, 'transfer');

        $this->assertNotSame(
            $attrs1['transactionId'],
            $attrs2['transactionId'],
            'Different keys must produce different transaction IDs.',
        );

        [$alice] = $this->reloadAccounts(self::ACC_ALICE);
        $this->assertSame('800.00', $alice->getBalance(), 'Alice debited twice');
        $this->assertCount(2, $this->em->getRepository(Transaction::class)->findAll());
    }

    /**
     * The Idempotency-Key supplied by the client must be echoed back in the
     * response attributes so clients can confirm their key was accepted.
     */
    public function testIdempotencyKeyAppearsInResponseAttributes(): void
    {
        $key = 'echo-key-' . bin2hex(random_bytes(4));

        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '50.00']), $key);

        $body  = $this->decodeJson($this->client->getResponse());
        $attrs = $this->assertSuccessEnvelope($body, 'transfer');

        $this->assertSame($key, $attrs['idempotencyKey']);
    }

    /**
     * A transfer without any Idempotency-Key must succeed and return null
     * for idempotencyKey in the response attributes.
     */
    public function testTransferWithoutIdempotencyKeyReturnsNullKey(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '50.00']));

        $body  = $this->decodeJson($this->client->getResponse());
        $attrs = $this->assertSuccessEnvelope($body, 'transfer');

        $this->assertNull($attrs['idempotencyKey']);
    }

    // =========================================================================
    // 5. Database Side-Effects
    // =========================================================================

    /**
     * A successful transfer must produce exactly two ledger entries:
     *  - one DEBIT for the sender (balance decreases)
     *  - one CREDIT for the receiver (balance increases)
     */
    public function testSuccessfulTransferCreatesLedgerEntries(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '150.00']));

        $body  = $this->decodeJson($this->client->getResponse());
        $attrs = $this->assertSuccessEnvelope($body, 'transfer');
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->em->clear();

        /** @var Transaction $txn */
        $txn     = $this->em->find(Transaction::class, $attrs['transactionId']);
        $entries = $txn->getLedgerEntries()->toArray();

        $this->assertCount(2, $entries, 'Must produce exactly two ledger entries.');

        $types = array_map(static fn (LedgerEntry $e) => $e->getType(), $entries);
        $this->assertContains(LedgerEntryType::DEBIT,  $types);
        $this->assertContains(LedgerEntryType::CREDIT, $types);

        foreach ($entries as $entry) {
            $this->assertSame('150.00', $entry->getAmount());
        }
    }

    /**
     * A successful transfer must write a TRANSFER_SUCCESS audit log entry
     * containing the transaction ID and involved account IDs.
     */
    public function testSuccessfulTransferCreatesAuditLog(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '75.00']));

        $body  = $this->decodeJson($this->client->getResponse());
        $attrs = $this->assertSuccessEnvelope($body, 'transfer');

        $this->em->clear();

        /** @var AuditLog[] $logs */
        $logs = $this->em->getRepository(AuditLog::class)->findBy(['event' => 'TRANSFER_SUCCESS']);

        $this->assertCount(1, $logs, 'Exactly one TRANSFER_SUCCESS audit entry must be created.');
        $this->assertSame($attrs['transactionId'], $logs[0]->getTransactionId());
    }

    /**
     * Even when a transfer is rolled back (insufficient balance), a
     * TRANSFER_FAILED audit entry must still be persisted — AuditLogService
     * writes it via raw DBAL in auto-commit mode after the rollback.
     */
    public function testFailedTransferCreatesAuditLog(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '9999.00'])); // exceeds balance

        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->client->getResponse()->getStatusCode(),
        );

        $this->em->clear();

        $failedLogs = $this->em->getRepository(AuditLog::class)->findBy(['event' => 'TRANSFER_FAILED']);
        $this->assertCount(1, $failedLogs, 'TRANSFER_FAILED audit entry must persist despite rollback.');

        // The DB transaction was rolled back — no Transaction row should exist
        $this->assertCount(0, $this->em->getRepository(Transaction::class)->findAll());
    }

    /**
     * A blocked-account rejection must persist an ACCOUNT_BLOCKED audit entry
     * after the rollback, before the TRANSFER_FAILED entry.
     */
    public function testBlockedAccountCreatesAuditLog(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBodyFor(self::ACC_CHARLIE, self::ACC_BOB, '100.00'));

        $this->assertSame(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->client->getResponse()->getStatusCode(),
        );

        $this->em->clear();

        $this->assertCount(
            1,
            $this->em->getRepository(AuditLog::class)->findBy(['event' => 'ACCOUNT_BLOCKED']),
            'ACCOUNT_BLOCKED audit entry must be written.',
        );
        $this->assertCount(
            1,
            $this->em->getRepository(AuditLog::class)->findBy(['event' => 'TRANSFER_FAILED']),
            'TRANSFER_FAILED audit entry must also be written.',
        );
    }

    // =========================================================================
    // 6. Sequential Transfers
    // =========================================================================

    /**
     * Three back-to-back transfers must accumulate balance changes correctly.
     *
     *  TX1: Alice → Bob  100.00  → Alice 900.00  Bob 600.00
     *  TX2: Alice → Bob  200.00  → Alice 700.00  Bob 800.00
     *  TX3: Bob   → Alice 50.00  → Alice 750.00  Bob 750.00
     */
    public function testSequentialTransfersAccumulateCorrectly(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '100.00']));
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '200.00']));
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->postJson('/api/v1/transfer', $this->transferBodyFor(self::ACC_BOB, self::ACC_ALICE, '50.00'));
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        [$alice, $bob] = $this->reloadAccounts(self::ACC_ALICE, self::ACC_BOB);

        $this->assertSame('750.00', $alice->getBalance());
        $this->assertSame('750.00', $bob->getBalance());
        $this->assertCount(3, $this->em->getRepository(Transaction::class)->findAll());
    }

    /**
     * After draining an account to zero, any further transfer from that account
     * must be rejected with an insufficient-balance error.
     */
    public function testTransferAfterBalanceDrainedReturns422(): void
    {
        // Drain Alice completely
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '1000.00']));
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        // Attempt a second transfer from the now-empty account
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '0.01']));

        $body = $this->decodeJson($this->client->getResponse());

        $this->assertSame(Response::HTTP_UNPROCESSABLE_ENTITY, $this->client->getResponse()->getStatusCode());
        $this->assertErrorEnvelope($body, 'insufficient');

        [$alice] = $this->reloadAccounts(self::ACC_ALICE);
        $this->assertSame('0.00', $alice->getBalance(), 'Alice must still be at zero');
        $this->assertCount(1, $this->em->getRepository(Transaction::class)->findAll());
    }

    // =========================================================================
    // 7. Global audit log list (GET /api/v1/transfer/audit-logs)
    // =========================================================================

    /**
     * The audit-log endpoint must return the standard success envelope with
     * type "audit-log-list" even when there are no log entries.
     */
    public function testAuditLogsEndpointReturnsEmptyList(): void
    {
        $this->client->request('GET', '/api/v1/transfer/audit-logs');

        $response = $this->client->getResponse();
        $body     = $this->decodeJson($response);

        $this->assertSame(Response::HTTP_OK, $response->getStatusCode());
        $attrs = $this->assertSuccessEnvelope($body, 'audit-log-list');

        $this->assertSame(0,  $attrs['count']);
        $this->assertSame([], $attrs['items']);
    }

    /**
     * After a successful transfer, the TRANSFER_SUCCESS log must appear when
     * filtering by event=TRANSFER_SUCCESS.
     */
    public function testAuditLogsFilterByEventReturnsMatchingLogs(): void
    {
        // Create one successful transfer (generates TRANSFER_SUCCESS)
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '50.00']));
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        // Create one failed transfer (generates TRANSFER_FAILED)
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '9999.00']));

        // Filter for successes only
        $this->client->request('GET', '/api/v1/transfer/audit-logs?event=TRANSFER_SUCCESS');
        $body  = $this->decodeJson($this->client->getResponse());
        $attrs = $this->assertSuccessEnvelope($body, 'audit-log-list');

        $this->assertSame(1, $attrs['count']);
        $this->assertSame('TRANSFER_SUCCESS', $attrs['items'][0]['event']);
    }

    /**
     * The ?limit query parameter must cap the number of items returned.
     */
    public function testAuditLogsLimitIsRespected(): void
    {
        // Generate 3 transfers → 3 TRANSFER_SUCCESS + 3 TRANSFER_FAILED audit logs = 6 total
        // We only do 3 successful ones here
        foreach (['50.00', '60.00', '70.00'] as $amount) {
            $this->postJson('/api/v1/transfer', $this->transferBodyFor(self::ACC_DAVE, self::ACC_BOB, $amount));
        }

        $this->client->request('GET', '/api/v1/transfer/audit-logs?limit=2');
        $body  = $this->decodeJson($this->client->getResponse());
        $attrs = $this->assertSuccessEnvelope($body, 'audit-log-list');

        $this->assertCount(2, $attrs['items'], 'Response must honour limit=2');
        $this->assertSame(2,  $attrs['count']);
    }

    /**
     * The audit-log list items must contain the expected fields.
     */
    public function testAuditLogItemHasExpectedFields(): void
    {
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '25.00']));

        $this->client->request('GET', '/api/v1/transfer/audit-logs?event=TRANSFER_SUCCESS&limit=1');
        $body  = $this->decodeJson($this->client->getResponse());
        $attrs = $this->assertSuccessEnvelope($body, 'audit-log-list');

        $item = $attrs['items'][0];
        $this->assertArrayHasKey('id',            $item);
        $this->assertArrayHasKey('event',         $item);
        $this->assertArrayHasKey('transactionId', $item);
        $this->assertArrayHasKey('fromAccountId', $item);
        $this->assertArrayHasKey('toAccountId',   $item);
        $this->assertArrayHasKey('amount',        $item);
        $this->assertArrayHasKey('context',       $item);
        $this->assertArrayHasKey('createdAt',     $item);
        $this->assertSame('TRANSFER_SUCCESS', $item['event']);
    }

    // =========================================================================
    // 8. Entity-Scoped Audit Trail (GET /api/v1/audit/{entityType}/{entityId})
    // =========================================================================

    /**
     * Before any transfer, the entity audit endpoint returns an empty items array
     * with count 0 for a seeded account.
     */
    public function testEntityAuditTrailReturnsEmptyWhenNoEvents(): void
    {
        $aliceId = $this->aliceAccountId();

        $this->client->request('GET', '/api/v1/audit/Account/' . $aliceId);

        $this->assertSame(Response::HTTP_OK, $this->client->getResponse()->getStatusCode());
        $body  = $this->decodeJson($this->client->getResponse());
        $attrs = $this->assertSuccessEnvelope($body, 'audit-trail');

        $this->assertSame('Account', $attrs['entityType']);
        $this->assertSame($aliceId,  $attrs['entityId']);
        $this->assertSame(0,         $attrs['count']);
        $this->assertSame([],        $attrs['items']);
    }

    /**
     * After a successful transfer, entity-scoped audit returns TRANSFER_SUCCESS
     * with matching entityType / entityId and masked amount in metadata.
     */
    public function testEntityAuditTrailReturnsTransferSuccessAfterTransfer(): void
    {
        $aliceId = $this->aliceAccountId();

        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '42.50']));
        $this->assertSame(Response::HTTP_CREATED, $this->client->getResponse()->getStatusCode());

        $this->client->request('GET', '/api/v1/audit/Account/' . $aliceId);

        $body  = $this->decodeJson($this->client->getResponse());
        $attrs = $this->assertSuccessEnvelope($body, 'audit-trail');

        $this->assertGreaterThanOrEqual(1, $attrs['count']);
        $this->assertNotEmpty($attrs['items']);

        $first = $attrs['items'][0];
        $this->assertSame('TRANSFER_SUCCESS', $first['action']);
        $this->assertSame('Account',          $first['entityType']);
        $this->assertSame($aliceId,           $first['entityId']);
        $this->assertIsArray($first['metadata']);
        $this->assertArrayHasKey('amount', $first['metadata']);
        $this->assertNotSame('42.50', (string) $first['metadata']['amount'], 'Amount in stored metadata must be masked');
    }

    /**
     * The limit query parameter is honoured on the entity audit endpoint.
     */
    public function testEntityAuditTrailRespectsLimitParameter(): void
    {
        $aliceId = $this->aliceAccountId();

        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '1.00']));
        $this->postJson('/api/v1/transfer', $this->transferBody(['amount' => '2.00']));

        $this->client->request('GET', '/api/v1/audit/Account/' . $aliceId . '?limit=1');
        $body  = $this->decodeJson($this->client->getResponse());
        $attrs = $this->assertSuccessEnvelope($body, 'audit-trail');

        $this->assertCount(1, $attrs['items']);
        $this->assertSame(1, $attrs['count']);
    }

    // =========================================================================
    // 9. Protocol
    // =========================================================================

    /**
     * A GET request to the transfer creation endpoint must return 405
     * Method Not Allowed.
     */
    public function testGetRequestToTransferEndpointReturns405(): void
    {
        $this->client->request('GET', '/api/v1/transfer');

        $this->assertSame(
            Response::HTTP_METHOD_NOT_ALLOWED,
            $this->client->getResponse()->getStatusCode(),
        );
    }

    /**
     * Requesting a route that does not exist must return 404.
     */
    public function testUnknownRouteReturns404(): void
    {
        $this->client->request('GET', '/api/v1/does-not-exist');

        $this->assertSame(
            Response::HTTP_NOT_FOUND,
            $this->client->getResponse()->getStatusCode(),
        );
    }

    // =========================================================================
    // Assertion helpers
    // =========================================================================

    /**
     * Verifies the success envelope shape and returns `attributes` for further
     * resource-specific assertions.
     *
     * @param  array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function assertSuccessEnvelope(array $body, string $expectedType): array
    {
        $this->assertTrue($body['success'], 'Expected success=true in envelope.');
        $this->assertArrayHasKey('data',       $body);
        $this->assertArrayHasKey('type',       $body['data']);
        $this->assertArrayHasKey('attributes', $body['data']);
        $this->assertSame($expectedType,       $body['data']['type']);
        $this->assertIsArray($body['data']['attributes']);

        return $body['data']['attributes'];
    }

    /**
     * Verifies the error envelope shape and that `message` contains the
     * given substring (case-insensitive).
     *
     * @param array<string, mixed> $body
     */
    private function assertErrorEnvelope(array $body, string $messageContains): void
    {
        $this->assertFalse($body['success'], 'Expected success=false in envelope.');
        $this->assertArrayHasKey('message', $body);
        $this->assertStringContainsStringIgnoringCase(
            $messageContains,
            $body['message'],
            "Response message does not contain \"{$messageContains}\".",
        );
    }

    // =========================================================================
    // Fixtures & helpers
    // =========================================================================

    private function truncateTables(): void
    {
        $conn = $this->em->getConnection();
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['audit_logs', 'ledger_entries', 'transactions', 'accounts', 'users'] as $table) {
            $conn->executeStatement("TRUNCATE TABLE `{$table}`");
        }
        $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function seedAccounts(): void
    {
        $alice   = new User('alice@test.com',   'hashed_pw', 'Alice Test');
        $bob     = new User('bob@test.com',     'hashed_pw', 'Bob Test');
        $charlie = new User('charlie@test.com', 'hashed_pw', 'Charlie Test');
        $dave    = new User('dave@test.com',    'hashed_pw', 'Dave Test');
        $eve     = new User('eve@test.com',     'hashed_pw', 'Eve Test');

        foreach ([$alice, $bob, $charlie, $dave, $eve] as $user) {
            $this->em->persist($user);
        }
        $this->em->flush();

        $this->em->persist($this->buildAccount($alice,   AccountType::SAVINGS,    '1000.00',  self::ACC_ALICE, self::NAME_ALICE, self::IFSC_ALICE));
        $this->em->persist($this->buildAccount($bob,     AccountType::CURRENT,     '500.00',  self::ACC_BOB, self::NAME_BOB, self::IFSC_BOB));
        $this->em->persist($this->buildAccount($charlie, AccountType::SAVINGS,     '300.00',  self::ACC_CHARLIE, self::NAME_CHARLIE, self::IFSC_CHARLIE, blocked: true));
        $this->em->persist($this->buildAccount($dave,    AccountType::SAVINGS,   '60000.00',  self::ACC_DAVE, self::NAME_DAVE, self::IFSC_DAVE));
        $this->em->persist($this->buildAccount($eve,     AccountType::CURRENT,  '200000.00',  self::ACC_EVE, self::NAME_EVE, self::IFSC_EVE));

        $this->em->flush();
    }

    private function buildAccount(
        User        $owner,
        AccountType $type,
        string      $balance,
        string      $accountNumber,
        string      $beneficiaryName,
        string      $ifscCode,
        bool        $blocked = false,
    ): Account {
        $account = new Account($owner, $type);

        $prop = new \ReflectionProperty(Account::class, 'accountNumber');
        $prop->setValue($account, $accountNumber);

        $account->setBalance($balance);
        $account->setIfscCode($ifscCode);
        $account->setBeneficiaryName($beneficiaryName);

        if ($blocked) {
            $account->setBlocked(true);
        }

        return $account;
    }

    /**
     * Default JSON body for POST /api/v1/transfer (Alice → Bob).
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function transferBody(array $overrides = []): array
    {
        return array_merge([
            'from_account_id' => $this->accountId(self::ACC_ALICE),
            'to_account_id'   => $this->accountId(self::ACC_BOB),
            'amount'          => '100.00',
        ], $overrides);
    }

    /**
     * Transfer body from seeded fixture account numbers (resolves UUIDs from the DB).
     *
     * @return array<string, mixed>
     */
    private function transferBodyFor(string $fromAccountNumber, string $toAccountNumber, string $amount): array
    {
        return [
            'from_account_id' => $this->accountId($fromAccountNumber),
            'to_account_id'   => $this->accountId($toAccountNumber),
            'amount'          => $amount,
        ];
    }

    /** Primary key UUID for a seeded account (by public account number). */
    private function accountId(string $accountNumber): string
    {
        $this->em->clear();
        $account = $this->em->getRepository(Account::class)->findOneBy(['accountNumber' => $accountNumber]);
        $this->assertNotNull($account, "Account {$accountNumber} not found.");

        return $account->getId();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postJson(string $uri, array $body, ?string $idempotencyKey = null): void
    {
        $server = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT'  => 'application/json',
        ];

        if ($idempotencyKey !== null) {
            $server['HTTP_IDEMPOTENCY_KEY'] = $idempotencyKey;
        }

        $this->client->request('POST', $uri, [], [], $server, (string) json_encode($body));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(Response $response): array
    {
        $data = json_decode((string) $response->getContent(), true);
        $this->assertIsArray($data, 'Response body is not valid JSON.');

        return $data;
    }

    /**
     * @return list<Account>
     */
    private function reloadAccounts(string ...$accountNumbers): array
    {
        $this->em->clear();
        $repo     = $this->em->getRepository(Account::class);
        $accounts = [];

        foreach ($accountNumbers as $number) {
            $account = $repo->findOneBy(['accountNumber' => $number]);
            $this->assertNotNull($account, "Account {$number} not found after reload.");
            $accounts[] = $account;
        }

        return $accounts;
    }

    /** UUID primary key of Alice's seeded account. */
    private function aliceAccountId(): string
    {
        return $this->accountId(self::ACC_ALICE);
    }
}
