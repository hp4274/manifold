# OTP Verification Architectures & Alternatives Report

This report summarizes the primary methods for storing and verifying One-Time Passwords (OTPs) securely in web applications.

---

## Current Implementation in Manifold
In this project ([portal/lib.php:L237](file:///c:/xampp/htdocs/manifold/portal/lib.php#L237)), OTPs are **not stored in plain text**. Instead, they are hashed before insertion into the `applicant_otps` table:
```php
// Issuing OTP
$code_hash = password_hash($code, PASSWORD_DEFAULT);
// Saved to database column `code_hash`

// Verifying OTP
password_verify($input_code, $otp['code_hash']);
```

---

## Alternative OTP Verification Methods

### 1. Password Hashing (Bcrypt / Argon2) — *Current Method in Codebase*
- **Mechanism:** Store `password_hash($code)` in the DB.
- **Verification:** Compare user input using `password_verify($input, $hash)`.
- **Pros:** Prevents plain-text OTP exposure if DB is compromised.
- **Cons:** Requires a database write for every OTP issued.

### 2. HMAC Signed Tokens / Stateless OTPs (No DB Storage)
- **Mechanism:** Server generates token `HMAC_SHA256(secret, email + expires_at + otp_code)` and sends `(email, expires_at, signature)` as an encrypted cookie/JWT to the browser.
- **Verification:** Recompute `HMAC_SHA256(secret, email + expires_at + user_input_code)` and compare with the signature.
- **Pros:** Completely stateless; zero database writes or storage required.
- **Cons:** Cannot easily revoke a single OTP before expiration unless a revocation list is kept.

### 3. TOTP / HOTP (Time-Based / Counter-Based RFC 6238)
- **Mechanism:** Server and user share a static secret. The OTP is generated on-the-fly using `HMAC-SHA1(Secret, current_timestamp / 30s)`.
- **Verification:** Server checks `TOTP(Secret, now) == user_input`.
- **Pros:** No dynamic OTPs stored in database. Standardized by authenticator apps (Google Authenticator, Authy).
- **Cons:** Requires initial secret setup per user; not ideal for email/SMS login flows without client app.

### 4. Cryptographic Hashing with Salt (SHA-256 + Salt)
- **Mechanism:** Store `hash('sha256', $code . $salt)` or `sodium_crypto_pwhash` in DB.
- **Verification:** Hash `user_input . $salt` and compare against stored string.
- **Pros:** Fast verification with lower computational overhead than Argon2/Bcrypt.
- **Cons:** 6-digit OTPs have low entropy (1,000,000 possibilities), so salt and rate-limiting are mandatory to prevent rainbow table attacks.

### 5. Third-Party Managed Auth / OTP APIs (Twilio, Auth0, Firebase)
- **Mechanism:** Delegate generation, SMS/Email delivery, and verification to API services (e.g. Twilio Verify API: `client.verify.v2.services.verifications.create`).
- **Verification:** Call `verifications.checks.create(code: input_code)`. API returns `approved` or `denied`.
- **Pros:** Zero OTP logic or storage in local codebase; handles SMS routing & rate-limiting automatically.
- **Cons:** External dependency and API usage costs.
