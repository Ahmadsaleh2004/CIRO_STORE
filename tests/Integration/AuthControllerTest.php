<?php

namespace Tests\Integration;

use App\Controllers\AuthController;
use Tests\Support\ControllerTestCase;

/**
 * AuthController — the sign-in and registration rules, executed rather than described.
 *
 * These are the checks a reader of this repository would most want to see proven, and
 * until the seam in Controller::respond() existed they could not be run at all: every one
 * of them ends the request, which ended the test runner with it.
 *
 * The register() validation is a ladder of eight refusals in a fixed order, and the order
 * matters — a test asserting the message for a weak password has to send an otherwise
 * valid form, or it gets the earlier refusal instead. Each test below therefore starts
 * from a complete, valid form and breaks exactly one field.
 */
final class AuthControllerTest extends ControllerTestCase
{
    /**
     * A registration form that passes every check, to be broken one field at a time.
     *
     * @param  array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function validForm(array $overrides = []): array
    {
        return $this->withCsrf(array_merge([
            'full_name'               => 'New Customer',
            'email'                   => 'new.customer@gmail.com',
            'password'                => 'Str0ng!Pass',
            'confirm_password'        => 'Str0ng!Pass',
            'gender'                  => 'male',
            'privacy_policy_accepted' => '1',
        ], $overrides));
    }

    private function insertUser(
        string $email = 'existing@gmail.com',
        string $password = 'Str0ng!Pass',
        bool $verified = true
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (full_name, email, password, email_verified_at)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([
            'Existing Customer',
            $email,
            password_hash($password, PASSWORD_DEFAULT),
            $verified ? date('Y-m-d H:i:s') : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    // ── getCsrf ──────────────────────────────────────────────

    public function testGetCsrfHandsOutAToken(): void
    {
        $json = $this->callJson([new AuthController(), 'getCsrf'], [], [], 'GET');

        $this->assertTrue($json['success']);
        $this->assertNotEmpty($json['token']);
        $this->assertSame($_SESSION['csrf_token'], $json['token']);
    }

    // ── login ────────────────────────────────────────────────

    public function testLoginRefusesARequestWithoutACsrfToken(): void
    {
        $json = $this->callJson(
            [new AuthController(), 'login'],
            ['email' => 'a@gmail.com', 'password' => 'x']
        );

        $this->assertFalse($json['success']);
        $this->assertSame('csrf_invalid', $json['error_code']);
    }

    public function testLoginRefusesEmptyCredentials(): void
    {
        $json = $this->callJson(
            [new AuthController(), 'login'],
            $this->withCsrf(['email' => '', 'password' => ''])
        );

        $this->assertFalse($json['success']);
        $this->assertSame('Please enter your email and password.', $json['message']);
    }

    /**
     * The wrong password and an address that does not exist must be indistinguishable —
     * a different message for each turns the sign-in form into a way of asking whether
     * somebody has an account here.
     */
    public function testLoginGivesTheSameAnswerForAWrongPasswordAndAnUnknownAddress(): void
    {
        $this->insertUser('known@gmail.com', 'Str0ng!Pass');

        $wrongPassword = $this->callJson(
            [new AuthController(), 'login'],
            $this->withCsrf(['email' => 'known@gmail.com', 'password' => 'Wr0ng!Pass'])
        );

        $_SESSION = [];
        $unknownUser = $this->callJson(
            [new AuthController(), 'login'],
            $this->withCsrf(['email' => 'nobody@gmail.com', 'password' => 'Wr0ng!Pass'])
        );

        $this->assertFalse($wrongPassword['success']);
        $this->assertFalse($unknownUser['success']);
        $this->assertSame($wrongPassword['message'], $unknownUser['message']);
    }

    public function testLoginRefusesAnAccountWhoseEmailIsNotVerified(): void
    {
        $this->insertUser('unverified@gmail.com', 'Str0ng!Pass', verified: false);

        $json = $this->callJson(
            [new AuthController(), 'login'],
            $this->withCsrf(['email' => 'unverified@gmail.com', 'password' => 'Str0ng!Pass'])
        );

        $this->assertFalse($json['success']);
        $this->assertTrue($json['needs_verification']);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testASuccessfulLoginSignsTheUserInAndRotatesTheToken(): void
    {
        $userId = $this->insertUser('welcome@gmail.com', 'Str0ng!Pass');
        $before = $this->withCsrf(['email' => 'welcome@gmail.com', 'password' => 'Str0ng!Pass']);
        $oldToken = $before['csrf_token'];

        $json = $this->callJson([new AuthController(), 'login'], $before);

        $this->assertTrue($json['success'], 'The response was: ' . json_encode($json));
        $this->assertSame($userId, $_SESSION['user_id']);
        $this->assertSame('user', $json['type']);

        // The token is rotated on sign-in: a pre-authentication token must not stay valid
        // for an authenticated session.
        $this->assertNotSame($oldToken, $_SESSION['csrf_token']);
    }

    // ── register ─────────────────────────────────────────────

    public function testRegisterRefusesAOneCharacterName(): void
    {
        $json = $this->callJson([new AuthController(), 'register'], $this->validForm(['full_name' => 'A']));

        $this->assertFalse($json['success']);
        $this->assertSame('Full name must be at least 2 characters.', $json['message']);
        $this->assertSame(0, $this->countRows('users'));
    }

    public function testRegisterRefusesAnAddressThatIsNotAnEmail(): void
    {
        $json = $this->callJson([new AuthController(), 'register'], $this->validForm(['email' => 'not-an-email']));

        $this->assertFalse($json['success']);
        $this->assertSame('Please enter a valid email address.', $json['message']);
    }

    public function testRegisterRefusesAPasswordWithoutASymbol(): void
    {
        $json = $this->callJson([new AuthController(), 'register'], $this->validForm([
            'password'         => 'Str0ngPass',
            'confirm_password' => 'Str0ngPass',
        ]));

        $this->assertFalse($json['success']);
        $this->assertStringContainsString('at least 8 characters', $json['message']);
        $this->assertSame(0, $this->countRows('users'));
    }

    public function testRegisterRefusesMismatchedPasswords(): void
    {
        $json = $this->callJson([new AuthController(), 'register'], $this->validForm([
            'confirm_password' => 'An0ther!Pass',
        ]));

        $this->assertFalse($json['success']);
        $this->assertSame('Passwords do not match.', $json['message']);
    }

    public function testRegisterRefusesAFormWithoutThePrivacyPolicyAccepted(): void
    {
        $json = $this->callJson([new AuthController(), 'register'], $this->validForm([
            'privacy_policy_accepted' => '',
        ]));

        $this->assertFalse($json['success']);
        $this->assertSame('You must agree to the Privacy Policy.', $json['message']);
        $this->assertSame(0, $this->countRows('users'));
    }

    // ── logout ───────────────────────────────────────────────

    public function testLogoutClearsTheSignedInUser(): void
    {
        $_SESSION['user_id'] = $this->insertUser();

        $this->call([new AuthController(), 'logout'], $this->withCsrf());

        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }
}
