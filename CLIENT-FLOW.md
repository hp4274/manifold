# How a client becomes a confirmed client

Every sale in this system ends in one place: an `applications` row with
`status = 'complete'`. This document traces every route to that row — the
website form, a dealer, a distributor — and every branch that can be taken
along the way, including the ones that end badly.

Written against the code as it stands. Where a rate or a limit is quoted it is
the live value in the `settings` table; all of them are editable under
**Admin → Settings** and none of them rewrite a sale that already happened.

---

## 1. The cast, and the codes they carry

| Who                | Table          | Code   | Where the code comes from                                               |
| ------------------ | -------------- | ------ | ----------------------------------------------------------------------- |
| Applicant / client | `applications` | `MF……` | Issued to every applicant on submission — it is their own referral code |
| Dealer             | `dealers`      | `MD……` | Issued when the dealer record is created, never changes                 |
| Distributor        | `distributors` | `MX……` | Issued when the distributor record is created, never changes            |
| Office             | `admin_users`  | —      | Signs in at `/admin` with an email and password                         |

The apply form has **one** box for a code, and the prefix decides what it
means. A code is only ever one of the three things, so the box never books both
a partner's own sale and a customer referral reward. A customer's code does
book both a reward and commission, but the commission goes to the partner
behind that customer's own sale — see rule 2 below.

Share links are built by `referral_link()` and look like
`…/apply-stove.html?ref=MD000004` — the same URL shape whoever is sharing it.

### Money, at the values currently set

| Product    | Booking payment | Delivery payment | Total   |
| ---------- | --------------- | ---------------- | ------- |
| Stove      | ₹3,500          | ₹16,500          | ₹20,000 |
| TukTuk kit | ₹6,000          | ₹24,000          | ₹30,000 |

| Earned by                | Stove  | TukTuk kit | On what                                   |
| ------------------------ | ------ | ---------- | ----------------------------------------- |
| Dealer                   | ₹3,000 | ₹4,500     | Their own sales                           |
| Distributor — override   | ₹1,000 | ₹1,500     | Sales by a dealer under them              |
| Distributor — direct     | ₹3,000 | ₹4,500     | Sales they make themselves                |
| Customer referral reward | ₹500   | ₹500       | Per referred application                  |

A flat amount per sale, per product — not a share of what the sale is worth —
and earned in full when the delivery payment is verified. See §9.

Both prices and both commission shares are **frozen onto the application row**
at the moment it is created. Changing a rate under Settings tomorrow changes
what future sales pay, never what this one was worth.

---

## 2. Route A — the website form

The long route, and the only one where the client pays the company directly.

```
website form
    ↓  submit.php
status: submitted          ← nothing is asked of the client yet
    ↓  office clicks Approve (or Turn down)
status: booking_pending    ← payment email sent, portal opens
    ↓  client uploads the booking receipt
status: booking_review
    ↓  office accepts the receipt (or rejects it)
status: docs_pending       ← receipt emailed; finance checks the paperwork
    ↓  (or finance turns the documents down with a reason — the client is emailed
    ↓   what is wrong and asked for corrected ones; the status stays here)
    ↓  office marks the finance documents verified
status: confirm_pending    ← the client is asked: go ahead, or cancel?
    ↓  client answers in the portal
status: delivery_pending   ← delivery payment now open to the client
        (or cancelled      ← nothing more is owed; the booking amount is refunded)
    ↓  client uploads the delivery receipt
status: delivery_review
    ↓  office accepts the receipt (or rejects it)
status: complete           ← confirmed client; all commission now earned
```

### A1. Submission

The client fills in `apply-stove.html` or `apply-tuktuk.html`, which posts to
`admin/submit.php`. On success the row is written with:

- `status = 'submitted'` — the database default
- `reference_code` — the booking number, `MF-00000000` style, derived from the
  row id straight after insert
- `referral_code` — the client's own code, theirs for good
- `booking_amount`, `delivery_amount` — frozen from the price list
- whatever the code box resolved to (see §4)

**No email goes out and no portal opens.** The form answers on screen with the
booking number and says the team reviews it first. This is deliberate: the
company does not take a payment for a place it has not yet agreed to.

### A2. The office decides

In **Admin → Forms → Stove / TukTuk applications** (or on the dashboard), a row
sitting at _Waiting for approval_ carries two buttons:

| Action                | What happens                                                                                                                                                                                                |
| --------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Approve** (tick)    | `status → booking_pending`, `confirmed_at` stamped, the payment email goes out with the booking amount and the QR code, and the client's portal opens. No confirmation prompt — this is the ordinary answer |
| **Turn down** (cross) | `status → rejected`. Nothing is emailed, the portal stays shut. Asks for confirmation first                                                                                                                 |

Both are recorded in `status_log` against the admin who clicked.

Anything that recalculates a status leaves a `submitted` row alone
(`status_from_payments()` returns early), so no background path can approve an
application as a side effect. Approval is a decision, never a consequence.

### A3. The client's portal

Sign-in is at `/portal` and is by one-time code — there is no password. The
address is typed, a six-digit code is emailed, and the code is good for **10
minutes**, **5 attempts**, and at most **6 codes per hour per address**.

- An address with no record at all is told so by name, so a typo is visible.
- An address whose only application is still `submitted` is told _"Your
  application is with our team"_ — it does not pretend not to know them.
- One address can hold several roles (a dealer who also bought a stove). It is
  asked which was meant rather than guessed at.

Inside, each application shows a six-stage timeline, what has been paid of what
is due, and the payment box for whichever stage is open. A `submitted`
application shows the timeline and the wait, and **no QR code or upload box** —
there is nothing to pay yet.

### A4. Paying, in two stages

Each stage — `booking`, then `delivery` — is its own `payments` row with its own
proof file. The client pays by UPI against the QR code, then uploads a receipt
image or PDF with an optional reference.

Uploading moves the application to `booking_review` / `delivery_review`. The
delivery stage stays **locked** until the booking payment is verified — it does
not open early.

### A5. The office checks the receipt

In the application's Details drawer, **Payment** tab, each pending receipt has:

| Action                       | What happens                                                                                                                                                                           |
| ---------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Accept & send receipt**    | Payment marked `verified`, a receipt number is allocated, the application status is recalculated from its payments, and a receipt email with a PDF goes to the client                  |
| **Reject**                   | Payment marked `rejected` with the typed reason, the stage falls back to _due_, the proof file is **deleted from disk**, and the client is emailed the reason so they can upload again |
| **Remind** (bell on the row) | Sends a payment reminder and counts it on the row. Refused if both payments are settled, or if a receipt is already waiting to be checked                                              |

Rejecting a _verified_ payment is not possible — a decided payment cannot be
decided again; the rejection path applies to pending receipts only.

### A6. Complete

When both stages are verified the status becomes `complete`. That is the
confirmed client. `completed_at` is stamped, the row shows a tick, and the only
row action left is Delete.

`complete` is also the single definition of **earned** for money purposes
(`commission_is_earned()`), used by the admin, the dealer portal and the
distributor portal alike, so the three can never quote different figures.

---

## 3. Route B — a dealer or distributor signs the client up

The same route as A, entered on a different form. A partner who has agreed a
sale in person types the customer in, so that customer does not have to go home
and fill in the website form themselves.

**Money never passes through a dealer or a distributor.** The customer pays the
company, in their own portal, and goes through every step of §2 — the office
approves, they pay the booking amount, finance checks the documents, they
confirm delivery and pay the delivery amount. The partner is paid their
commission by us, out of what the customer paid us.

- Dealer: **Dealer portal → Add a client**
- Distributor: **Distributor portal → Add a client**
- Office, on a partner's behalf: the same form in the admin

`create_direct_sale()` writes one row and nothing else:

- `status = 'submitted'` — exactly where a website application starts. A partner
  entering a customer is not an approval; the office still decides.
- **no payment rows, no verified stamps** — the customer has paid nobody yet
- `sale_channel = 'direct'` — which form it came in on, and nothing more
- `entered_by_dealer` / `entered_by_distributor` — who keyed it in
- commission frozen by the same `commission_split()` as any other sale, and
  written to `commission_lines` by `sync_application_status()` when the delivery
  payment is verified, exactly as it is for a website sale
- `referred_by_*` where a customer sent them — see §4

Consequences worth knowing:

- The client signs in to the same portal, sees the same seven stages, uploads
  the same two receipts and gets the same emails and PDF receipts.
- The partner earns nothing until the customer has actually paid in full. There
  is no longer such a thing as a sale that is complete on arrival.
- **Stock leaves the partner's shelf when the sale completes**, not when it is
  typed in — `stock_take_on_completion()`, called from
  `sync_application_status()` and guarded by the ledger row, so a sale the
  office turns down costs the partner no stock and a sale that completes cannot
  be counted twice. The portals still refuse to record a client the partner has
  no unit for, so nothing can be promised out of an empty shelf.
- A dealer's client still books their distributor's override, because the
  override follows whoever signed that dealer up.

---

## 4. What the code in the box does to a website application

Resolved in `submit.php` at submission, in this order:

1. **Empty** — no reward, no commission. The sale earns nobody anything.
2. **`MF……`, a live customer code** — `referred_by_id` and `referred_by_code`
   are recorded and `referral_reward_status = 'pending'` at ₹500 for the
   customer who shared it. The new sale also **inherits the partner behind that
   customer's own sale**: their dealer and distributor are copied onto it and
   commission is frozen as if the partner had sold it directly. A dealer who
   finds a customer keeps the customers that customer goes on to find, and the
   referring customer is paid their reward on top — both, not one.

   The partner is re-checked, not copied blind. A dealer switched off or no
   longer approved books nothing, and because their distributor's share is an
   override on a dealer sale, the distributor takes nothing either. Where the
   first sale was a distributor's own direct sale, it carries down as one.

   Inheritance follows the chain: a sale that inherited a dealer passes that
   dealer to anyone **it** refers, with no limit on depth. The **reward** does
   not: it is paid to the direct referrer only. If C4 refers C5 and C5 refers
   C6, C5 is paid on C6 and C4 is paid nothing — one level, every time.
3. **`MD……`, an approved and active dealer** — `dealer_id` set, the dealer's
   commission for that product frozen onto the row, and the override frozen for
   the distributor that dealer answers to. Every dealer has one, so every dealer sale books an
   override. This follows the dealer's record, **never the form** — a client
   cannot type a distributor into a sale.
4. **`MX……`, an active distributor** — `distributor_id` set, the direct
   commission for that product frozen, and no dealer is involved.
5. **Anything else** — a code that matches nothing, a switched-off dealer, or a
   dealer the office has not approved yet, resolves to nothing. The application
   is still accepted and earns nobody anything. The client is told in the
   payment email that the code could not be matched, and is invited to reply
   with the right one.

`dealer_for_code()` is the single gate for point 5: a dealer must be both
`is_active = 1` **and** `approval_status = 'approved'` for their code to book
anything. That check sits in the shared function, so the public form, the admin
and any other route all enforce it identically.

### Who sold it and who sent them are two different questions

The website form answers both from one code, because it only has one to work
with: an `MF……` code names the referrer *and* hands the sale to that customer's
own partner. A partner recording a sale in their own portal answers them
separately, and this is where a customer can send somebody to a dealer who is
not their own.

`direct-sale-form.php` carries an optional **"Referred by a customer"** box that
takes an `MF……` code. Whatever goes in it:

- **the sale belongs to the partner entering it** — their dealer commission,
  their distributor's override, or the distributor's direct rate;
- **the referrer is paid their reward out of that same sale**, whoever their own
  dealer is.

So a customer of D1 (under Dis1) can introduce somebody who buys from D5 (under
Dis2): D5 takes the dealer commission, Dis2 the override, and the D1 customer
their ₹500. D1 and Dis1 earn nothing, because they did not sell it. The reward
follows the person; the commission follows the sale.

Three things are refused rather than quietly dropped, because the partner is
standing with the customer and can read the code again:

- a partner code (`MD……` / `MX……`) in that box — the sale is already theirs;
- a code no customer holds, or one whose own booking payment is not yet
  verified;
- the buyer's own code — nobody refers themselves.

### When the referral reward is actually payable

`reward_is_payable()` — all three at once:

- reward still `pending`,
- the referred client's **booking** payment has been verified,
- the referred application is not `rejected`.

The office transfers it by hand under **Admin → Referrals** and marks it sent
(the referrer gets an email), or cancels it with a note. Rewards are never paid
automatically.

---

## 5. Where dealers come from

A dealer only books commission once approved, so how one is created matters.

| Route                                                                        | Ends at                          | Code books commission?                                            |
| ---------------------------------------------------------------------------- | -------------------------------- | ----------------------------------------------------------------- |
| Admin → Dealers → Add a dealer                                               | `approval_status = 'approved'`   | Immediately                                                       |
| Admin → Distributors → a distributor's Dealers tab → Add a dealer under them | `approved`, `distributor_id` set | Immediately — the office approving its own entry would be theatre |
| A distributor asks for one from their own portal                             | `approval_status = 'pending'`    | **No.** Their code is dead until the office approves it           |

A pending request appears in **Admin → Distributors**, in that distributor's
Dealers tab, with **Approve** and **Turn down**. Approving is what makes the
code live; turning it down leaves it dead for good.

**The ceiling.** One distributor may hold at most `dealer_limit` dealers —
currently **10**, editable under Settings. Pending requests count towards it,
so the queue cannot be used to get around it. The limit is enforced on the
server in every route that creates or approves a dealer, not only in the
screens that warn about it.

**Every dealer answers to a distributor.** There is no such thing as one
without: the field is required in every form that creates a dealer, the
controllers refuse a request that omits it, and `dealers.distributor_id` is
`NOT NULL` in the database. A dealer can be _moved_ to another distributor —
under **Admin → Dealers**, by editing them — but never left with none.

That is also why a distributor holding dealers cannot be deleted. The office is
told to move them first, and the foreign key is `ON DELETE RESTRICT` so the
database refuses it too rather than quietly nulling the link.

---

## 6. Everything that can go wrong, and what happens

| Situation                                       | Outcome                                                                                                                                |
| ----------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------- |
| Application turned down at approval             | `status = 'rejected'`. No payment email, no portal. The client sees nothing because they were never let in                             |
| Application rejected after payments began       | Portal shows "Not proceeding" and no payment box. Any referral reward on it stops being payable                                        |
| Booking receipt rejected                        | Stage back to _due_, proof deleted, client emailed the reason, may upload again. No limit on retries                                   |
| Delivery receipt rejected                       | Same, at the delivery stage. The booking payment stays verified                                                                        |
| Client never pays                               | Row sits at `booking_pending`. The bell sends reminders and counts them; nothing expires on its own                                    |
| Client uploads the wrong file                   | Office rejects it with a reason; the file is removed from disk at that point                                                           |
| Code typed for a pending or switched-off dealer | Treated as no code. Sale earns nobody. Told in the email                                                                               |
| Dealer switched off after their sales           | Past sales keep their frozen commission and are still owed. Their code stops booking new ones                                          |
| Distributor switched off                        | Same — the code stops working, what is owed stays owed                                                                                 |
| Dealer deleted                                  | Their applications survive; `dealer_id` becomes NULL. The customers are never lost with the dealer                                     |
| Distributor deleted                             | Refused while any dealer is still under them — move those dealers first. With none left, the distributor goes and their sales are kept |
| Same email applies twice                        | Two applications, one portal. Both are listed, each with its own stage                                                                 |
| Client signs in before approval                 | Told their application is with the team — not that the address is unknown                                                              |
| Payment email fails to send                     | The status change still stands; the admin sees "Status saved, but the email could not be sent" and the reason lands in `email_log`     |
| Direct sale keyed in twice                      | Two complete applications and commission on both. Nothing detects the duplicate — check the partner's client list first                |
| Distributor at their dealer limit               | Both the form and the server refuse. Raise the limit under Settings, or move a dealer to another distributor                           |

---

## 7. A distributor's own request, in detail

**Distributor portal → Dealers → Add a dealer** (`distributor/add-dealer.php`)
takes the same sixteen fields the office fills in, and writes the dealer
immediately with:

- `approval_status = 'pending'` — the code is issued now and never changes, it
  simply books nothing until the office says yes
- `distributor_id` and `requested_by` — both the signed-in distributor

The allowance is checked twice: the Dealers page hides the button at the limit,
and this page refuses the post as well, because a form can be submitted without
ever seeing that page.

The request then appears in **Admin → Distributors**, in that distributor's
Dealers tab, marked _Waiting for approval_, with **Approve** and **Turn down**.
Approving is the only thing that makes the code live.

---

---

## 8. Stock: how a partner comes to have units to sell

**Built and working.** Everything in this section describes code that runs
today, with the file and function names to find it by.

A direct sale (§3) hands over a unit out of the partner's own hand, so they have
to have bought it first. Stock moves _down_ the chain one tier at a time and
money moves _up_ it, and each tier only ever deals with the tier next to it.

```
   office ──────units─────▶ distributor ─────units─────▶ dealer ─────unit────▶ client
          ◀─────money──────             ◀─────money─────        ◀────money────
       admin/stock.php            distributor/stock.php      the sale is recorded
       releases the units         releases the units         in *-/add-client.php
```

The office never sells to a dealer, and a dealer never buys from the office.

---

### 8.1 The two tables

**`stock_orders`** — one row per request, from raised to decided.

| Column                                           | Holds                                                                                                                |
| ------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------- |
| `buyer_type`                                     | `distributor` or `dealer` — who is buying                                                                            |
| `buyer_id`                                       | Their row id in `distributors` / `dealers`                                                                           |
| `seller_distributor_id`                          | The distributor selling. **NULL means the office sold it** — that is the only thing that distinguishes the two flows |
| `total_amount` | What the whole order comes to, frozen when it is raised |
| `status`                                         | `pending` → `approved` or `rejected`                                                                                 |
| `reference`, `proof_path`, `note`                | What the buyer supplied — UTR, the uploaded proof, a free note                                                       |
| `reject_reason`                                  | Kept for the buyer to read                                                                                           |
| `requested_at`, `decided_at`, `decided_by_admin` | The audit trail                                                                                                      |

**`stock_order_items`** — what the order is *for*, a line per product:
`order_id`, `product`, `quantity`, `unit_price`, `line_total`, unique on
`(order_id, product)`.

> One order can be for both products, because the partner pays once and uploads
> one proof. Two orders against a single payment would let the tier above
> approve half of it, which is not a thing anybody can act on. So the products
> are lines, and the decision belongs to the order.

**`stock_ledger`** — one row per movement. A balance is the sum of these rows;
nothing anywhere stores a balance.

| Column                       | Holds                                                |
| ---------------------------- | ---------------------------------------------------- |
| `owner_type`, `owner_id`     | Whose shelf moved                                    |
| `product`                    | Which product                                        |
| `units`                      | **Signed.** In is positive, out is negative          |
| `value`                      | **Signed, and always at cost.** Moves with the units |
| `reason`                     | `purchase`, `sale`, `transfer_out`, `adjustment`     |
| `order_id`, `application_id` | What caused it — the order, or the sale              |
| `note`, `created_at`         | Free text on adjustments, and when                   |

Why a ledger and not two balance columns: a balance kept beside the movements is
a second number to keep in step, and when the two disagree there is no way to
find out which is right. Summing the rows cannot drift, and it answers "where
did that unit go" for free.

---

### 8.2 Prices

Four figures under **Admin → Settings → Stock prices**, stored in `settings`:

| Setting key                      | Seeded at | What it is                                   |
| -------------------------------- | --------- | -------------------------------------------- |
| `stock_price_distributor_stove`  | ₹17,000   | A distributor buys a stove from the office   |
| `stock_price_distributor_tuktuk` | ₹25,500   | A distributor buys a TukTuk kit              |
| `stock_price_dealer_stove`       | ₹18,500   | A dealer buys a stove from their distributor |
| `stock_price_dealer_tuktuk`      | ₹27,000   | A dealer buys a TukTuk kit                   |

Read with `stock_price($buyerType, $product)`. **These are seeded values, not
agreed prices — set your real ones before anybody orders.**

A partner's margin is the difference between what they paid and what the client
pays. At the seeded figures a distributor buying a TukTuk kit at ₹25,500 and
selling it to a dealer at ₹27,000 makes ₹1,500; the dealer selling it on at the
₹30,000 retail price makes ₹3,000.

Commission is **on top of that margin** and is unaffected by any of it — a
stocked sale books the dealer's commission and the distributor's override
exactly as a share-link sale does.

---

### 8.3 Ordering — the buyer's side

**Distributor portal → Stock → Order more from the office**, or **Dealer portal
→ Stock → Order more**. Both use the same form,
`admin/partials/stock-order-form.php`.

The buyer enters **how many of each product** they want — either one, or both,
on the same order — and sees each line and the running total as they type. They
pay **first**, outside the system, then upload the proof and submit. What happens on submit:

1. The proof is taken with `store_upload('payment_proof', PAYMENT_PROOF_DIR)`.
   **No proof, no order** — without it the tier above has nothing to check, so
   the upload happens before anything is written and a missing or oversized file
   stops the order with a message.
2. `stock_order_create()` writes the row at `pending`, freezing
   `unit_price` and `total_amount` from today's setting.
3. Nothing reaches anybody's shelf. The units are not theirs yet.

The form's live total is a convenience only — `stock_order_create()` works the
price out again on the server, so a total edited in the browser changes nothing.

`stock_order_create()` refuses: an order with nothing on it, a negative
quantity, more than 1,000 units or ₹90,000,000 altogether (past that the total
overflows the column it is stored in and the insert throws, which would reach
the partner as a blank page), a dealer with no distributor to buy from, and a
product with no price set. A product left at zero is simply not on the order.

---

### 8.4 Releasing — the seller's side

| Order                | Decided at                                               | By               |
| -------------------- | -------------------------------------------------------- | ---------------- |
| Distributor → office | **Admin → Stock** (`admin/stock.php`)                    | The office       |
| Dealer → distributor | **Distributor portal → Stock** (`distributor/stock.php`) | That distributor |

Both screens list the orders with the proof beside the decision, so the receipt
is looked at next to the button rather than accepted blind. The proof opens
**over the page** in the file viewer, not in a new tab.

Each screen serves the proof its own way, and neither takes a path from the
request: the admin reads it through `admin/file.php`; the distributor reads it
through `distributor/proof.php`, which looks the file up **from the order** and
only if that order was one they were asked to release.

**Approving** (`stock_order_approve()`) does exactly this, in order:

1. If a distributor is the seller, check they hold enough of **every** product
   on the order — all of them, before any of them moves. Being short on one line
   stops the whole order with a message naming that product, and **nothing
   moves**: releasing the stoves and then failing on the kits would leave half
   an order approved against a whole payment.
2. Mark the order `approved`, stamped and dated.
3. For each line, write the buyer a `purchase` row: `+quantity` units,
   `+line_total` value — what they paid for that product.
4. If a distributor is the seller, write them a `transfer_out` row per line:
   `-quantity` units, valued at **their own cost**, not at what they charged.

That last point is the one to understand. The distributor's shelf is valued at
what the units cost _them_; the extra the dealer paid is margin, and margin is
money, not stock, so it never appears in the ledger. Selling stock at a profit
therefore reduces the shelf value by less than the dealer's shelf gains.

**Turning it down** (`stock_order_reject()`) marks it `rejected` with the typed
reason and moves nothing. The buyer sees the reason on their own Stock page.

An order can only be decided once — a second attempt returns "That order has
already been decided", so a double-click or a stale tab cannot release the same
units twice.

---

### 8.5 Selling to a client — what comes off

Recording a direct sale (§3) in `dealer/add-client.php` or
`distributor/add-client.php` does two things in order:

1. **Before writing the sale**, check the seller holds enough:
   `stock_units()` against the sale's `units_required`. If they are short the
   sale is **refused** with a message naming what they have. This is enforced in
   the controller, not only in the form.
2. **After writing the sale**, `stock_take_for_sale()` writes a `sale` row:
   `-units`, and value at cost, carrying the new `application_id` — which is
   what ties a missing unit to the client who has it.

Cost here is `stock_unit_cost()`: **the average of what the partner actually
paid**, or `held value ÷ held units`. Two orders at different prices leave a
blended cost, so a sale can never deduct more value than the units were bought
for.

Sales through a **share link**, where the client pays Manifold directly, take
nothing off any shelf — that unit ships from the company.

---

### 8.6 A worked example, end to end

A distributor and one dealer under them, TukTuk kits, at the seeded prices.

| #   | What happens                                                        | Distributor shelf        | Dealer shelf       |
| --- | ------------------------------------------------------------------- | ------------------------ | ------------------ |
| 1   | Distributor orders 10 from the office, pays ₹255,000, uploads proof | 0 · ₹0 _(order pending)_ | 0 · ₹0             |
| 2   | Office approves                                                     | **10 · ₹255,000**        | 0 · ₹0             |
| 3   | Dealer orders 4, pays the distributor ₹108,000, uploads proof       | 10 · ₹255,000            | 0 · ₹0 _(pending)_ |
| 4   | Distributor approves                                                | **6 · ₹153,000**         | **4 · ₹108,000**   |
| 5   | Dealer sells 2 to a client                                          | 6 · ₹153,000             | **2 · ₹54,000**    |
| 6   | Distributor sells 1 themselves                                      | **5 · ₹127,500**         | 2 · ₹54,000        |

Reading step 4: the distributor loses 4 units and ₹102,000 — 4 × their own
₹25,500 cost — while the dealer gains 4 units at the ₹108,000 they paid. The
₹6,000 difference is the distributor's margin, and it is money, not stock.

The ledger behind the distributor's ₹127,500:

| Reason             | Units | Value        |
| ------------------ | ----- | ------------ |
| Bought in          | +10   | +₹255,000    |
| Passed to a dealer | −4    | −₹102,000    |
| Sold to a client   | −1    | −₹25,500     |
| **Balance**        | **5** | **₹127,500** |

---

### 8.7 Where each figure appears

| Screen                         | Shows                                                                                |
| ------------------------------ | ------------------------------------------------------------------------------------ |
| Dealer / Distributor dashboard | A stock tile: total units, the per-product split, value at cost, and a link to order |
| Dealer / Distributor → Stock   | Balances, the order form, their own orders and their full movement history           |
| Distributor → Stock            | Also their dealers' orders, to release or turn down                                  |
| Admin → Stock                  | The office's queue, plus every distributor's holding and the value out with them     |

The office's four tiles: orders waiting, units out with distributors, the value
of that stock at what they paid, and the total taken in for stock across every
released order.

---

### 8.8 Correcting a count

Units go missing in ways no order explains — damaged in transit, one that never
arrived, a miscount. **Admin → Stock → Adjust the count** writes an
`adjustment` row against a distributor: a positive number adds, a negative one
takes away, valued at that distributor's current average cost, with a note
saying why. The note is optional in the form — write one anyway; an
unexplained adjustment is the one ledger row nobody can account for later.

It is its own `reason`, so an adjustment never reads as a sale in anybody's
history.

There is no adjustment screen for dealers. A dealer's count is corrected
through their distributor today; if that becomes a real need, the same helper
(`stock_move()`) already takes `owner_type = 'dealer'`.

---

### 8.9 What can go wrong

| Situation                                                   | What happens                                                                            |
| ----------------------------------------------------------- | --------------------------------------------------------------------------------------- |
| Order raised with no proof                                  | Refused before anything is written. The order does not exist                            |
| Proof is over 10 MB or the wrong type                       | Same — `store_upload()` returns nothing and the order is refused                        |
| Distributor approves an order for more units than they hold | Refused with the count they actually hold. Nothing moves                                |
| Two people approve the same order at once                   | The second gets "already been decided". One release, one set of ledger rows             |
| Partner tries to record a sale with no stock                | Refused, naming what they hold. The sale is not written                                 |
| A price changes between two orders                          | Each order keeps the price it was raised at. The shelf cost becomes the blended average |
| A rejected order                                            | Nothing moves; the reason shows on the buyer's Stock page. They can order again         |
| A dealer sells a unit bought at a different price           | Deducted at the blended average, never at today's price                                 |
| Stock ordered but the payment later bounces                 | The office adjusts the count down with a note. Nothing rewrites the original order      |
| A distributor is deleted                                    | Refused while any dealer is under them (§5). Their ledger rows stay                     |

---

### 8.10 The code, by file

| File                                    | What it does                                                                                                                                                                                                                                                                         |
| --------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `admin/lib.php`                         | The whole stock API: `stock_price()`, `stock_balance()`, `stock_units()`, `stock_unit_cost()`, `stock_move()`, `stock_history()`, `stock_order_create()`, `stock_order_approve()`, `stock_order_reject()`, `stock_orders_for()`, `stock_orders_to_decide()`, `stock_take_for_sale()` |
| `admin/stock.php`                       | The office's queue, holdings, and the adjustment form                                                                                                                                                                                                                                |
| `distributor/stock.php`                 | Balances, ordering from the office, releasing to dealers, history                                                                                                                                                                                                                    |
| `dealer/stock.php`                      | Balances, ordering from their distributor, history                                                                                                                                                                                                                                   |
| `distributor/proof.php`                 | Serves a dealer's proof to that dealer's distributor only                                                                                                                                                                                                                            |
| `admin/partials/stock-order-form.php`   | The order form, shared by both portals                                                                                                                                                                                                                                               |
| `admin/partials/stock-orders-table.php` | A partner's own orders and their state                                                                                                                                                                                                                                               |
| `admin/partials/stock-ledger-table.php` | The movement history                                                                                                                                                                                                                                                                 |
| `admin/settings.php`                    | The four prices                                                                                                                                                                                                                                                                      |

## 9. How commission is worked out, and paid, tranche by tranche

**Built and working.** Commission is a flat amount per sale, set per product,
and earned when the sale's delivery payment is verified. The figures live in
`commission_lines`, one row per party per sale, which is what every screen and
every voucher reads.

### What a sale pays

The amount is read from Settings when the application arrives and written onto
the row, so a change to the figures never rewrites a sale already made.

| Earned by                | Stove  | TukTuk kit |
| ------------------------ | ------ | ---------- |
| Dealer                   | ₹3,000 | ₹4,500     |
| Distributor — override   | ₹1,000 | ₹1,500     |
| Distributor — direct     | ₹3,000 | ₹4,500     |

Those are the starting figures. Both the office (**Admin → Settings →
Commission**) and R&F (**R&F → Settings**), who pay it, edit the same six
amounts.

### When it is earned

**In full, at delivery.** The booking payment on its own earns nobody anything:

- Booking verified → the sale is under way, nothing is earned.
- Delivery verified → the whole amount is earned and payable, and the sale is
  complete.

Financing is arranged between the client and their lender and never passes
through this system: the office does not record a loan, and no commission is
worked out on one.

### Worked example — a dealer sells one stove

| What happens                | What the dealer earns | What their distributor earns |
| --------------------------- | --------------------- | ---------------------------- |
| ₹3,500 booking verified     | —                     | —                            |
| ₹16,500 delivery verified   | ₹3,000                | ₹1,000                       |

A kit pays ₹4,500 and ₹1,500 the same way. A distributor selling it themselves
takes the direct figure — ₹3,000 on a stove — and no dealer is involved.

### When each part is earned

The whole amount becomes payable when the **delivery payment is verified**. A
sale sitting on its booking payment has earned nobody anything yet, and shows on
the partner's dashboard as still to come rather than as owed.

### Rules this has to hold to

- **Frozen per sale.** The commission amounts are stamped onto the application
  when it arrives, exactly as prices already are. Changing an amount under
  Settings changes future sales, never a sale already made.
- **Stored per sale, per party.** The line is written when the delivery payment
  is verified, so it can be shown, claimed and audited on its own.
- **An amount of zero earns nothing.** A partner whose figure is zero on the day
  the sale arrived has no line written, rather than a line worth nothing.
- **A rejected payment un-earns the sale.** A delivery receipt that is rejected
  (§2 A5) takes its commission line with it, and the sale goes back to being
  still to come.
- **A claimed line is never rewritten.** If a rejection lands after the line was
  claimed, the correction goes on the next voucher (§10) rather than rewriting a
  paid one.
- **Direct sales earn at once.** A partner's own sale (§3) is created complete
  and paid in full, so the amount is earned the moment it is recorded.

### Where it lives

| Piece | Where |
|---|---|
| The amounts | `settings.commission_<kind>_<product>` — kind being `dealer`, `override` or `direct` — edited under **Admin → Settings → Commission** or **R&F → Settings**, and frozen onto `applications.dealer_commission` / `distributor_commission` when a sale arrives |
| The figures | `commission_lines` — one row per application and party, written at the `delivery` stage, with `paid_amount` and `amount`. Unique on application, party and stage, so a sale is earned once. `gst_amount`, `base_amount` and `rate` are left from the percentage scheme and stay at zero for new lines |
| The maths | `commission_value()`, `commission_split()`, `tranche_is_paid()`, `commission_write_lines()`, `commission_earned()`, `commission_pipeline()` in `admin/lib.php` |
| The check | `admin/tests/voucher-chain.php`, run from the command line |

`commission_write_lines()` is a reconciliation, not an increment: it is called
after anything that verifies or un-verifies a payment, writes a line for every
tranche that is earned, and takes away any that no longer is. Running it twice
changes nothing, and a line already sitting on a voucher is never removed —
the correction lands on the next voucher instead.

## 10. Commission vouchers: how a partner actually gets paid

**Built and working.** File and function names are given so the code can be
found by them, and §9 is built too — a voucher claims tranches, so a partner can
be paid the booking share of a sale whose delivery payment is months away.

Getting commission into a partner's bank is a separate thing, and it does not go straight there: it
travels up the chain as a claim, and the money comes back down through **R&F**,
the agency that holds the float and makes the transfers.

A voucher is that claim. It is raised for the commission a partner has earned
and not yet been paid, it carries a frozen list of the sales it is made of, and
it is signed off at each tier before the money moves.

### The cast, with one addition

| Who            | Their part in a voucher                                                                                      |
| -------------- | ------------------------------------------------------------------------------------------------------------ |
| Dealer         | Raises a voucher for their own commission                                                                    |
| Distributor    | Approves their dealers' vouchers, then raises one bundle: those dealers plus their own commission            |
| **R&F**        | The paying agent. Receives the bundle, presents it to the office, and makes the actual transfers once funded |
| Office (admin) | Approves what is claimed and releases the money to R&F                                                       |

R&F is not a partner and earns no commission. It is a **role on an admin
account** (`admin_users.role = 'rf'`), so R&F signs in at the same door as the
office — `/admin/login.php` — and lands somewhere else entirely: `/rf/`, which
shows bundles and nothing else. No clients, no stock, no rates, no settings.

The guard runs both ways. `require_login()` sends an R&F account away from every
office page, and `require_rf()` sends an office account away from every R&F page.
Neither reaches the other by typing a URL.

**The account**: `rf@manifold.com`, password `rf123`. Change that password
before this is used for real — it is written in this file, which is in the repo.

### The chain

```
        claim travels up                          money travels down
  ┌──────────────────────────────┐        ┌──────────────────────────────┐
  │                              ▼        │                              ▼
dealer ──▶ distributor ──▶ R&F ──▶ office ┘                          dealer
           (bundles their own                                   and distributor
            dealers + themselves)                              paid by R&F
```

Step by step:

1. **Dealer raises a voucher.** Amount = their commission earned on completed
   sales, less everything already paid to them. It goes to their distributor.
2. **Distributor approves or turns down each one.** They are the first check:
   they know whether that dealer's sales are real.
3. **Distributor raises a bundle.** One voucher containing every dealer voucher
   they have approved, plus their own commission — override and direct — on the
   same earned-less-paid basis.
4. **Bundle goes to R&F.** R&F checks it as a payment instruction: bank details
   present, no duplicate claim, totals add up.
5. **R&F presents it to the office.** The office is the only party that can say
   the money is owed.
6. **Office approves and releases the funds to R&F**, as one transfer against
   the whole bundle.
7. **R&F pays each dealer and distributor** and marks each line paid with its
   own reference. The bundle closes when every line in it is paid.

Money is never claimed twice because a voucher line names the sales it covers,
and a sale already on an open or paid voucher cannot appear on another.

### Voucher states

| State              | Sits with                           | Moves on when                                       |
| ------------------ | ----------------------------------- | --------------------------------------------------- |
| `draft`            | The partner                         | They submit it                                      |
| `with_distributor` | Their distributor                   | The distributor approves or turns it down           |
| `bundled`          | The distributor's bundle            | The bundle is submitted to R&F                      |
| `with_rf`          | R&F                                 | R&F forwards it to the office, or sends it back     |
| `with_admin`       | The office                          | The office approves or rejects it                   |
| `funded`           | R&F                                 | The office has transferred the money to R&F         |
| `paid`             | Closed                              | R&F has paid the partner and recorded the reference |
| `rejected`         | Back with the raiser, with a reason | They fix it and raise again                         |
| `cancelled`        | Closed                              | Withdrawn before it was funded                      |

A distributor's own commission travels in the bundle it raises — so a
distributor is both an approver of dealer vouchers and a claimant on the same
document. That is deliberate: one bundle per distributor per cycle is one thing
for R&F to pay and one thing for the office to look at.

### What a voucher is worth, and how a sale is only paid once

A voucher is built from **lines**, and a line names one sale
(`commission_voucher_lines`: `voucher_id`, `party_type`, `party_id`,
`application_id`, `amount`). Its amount is the sum of its lines, frozen when it
is raised.

`voucher_claimable()` finds the sales to build it from: completed sales
(`commission_is_earned()`, the definition the portals and the admin already
share) where that partner's commission is above zero, **minus anything already
sitting on a line of theirs**.

The line is what makes double payment impossible, and the detail that matters is
that a line belongs to a *party*, not just to a sale:

> One completed sale owes two people — the dealer their commission and that
> dealer's distributor the override. The unique key is
> `(application_id, party_type, party_id)`, so a dealer claiming a sale does not
> block the distributor's override on it, and neither can claim their own share
> twice. The database enforces that, not only the query that builds the voucher.

**Already paid** stays where it was: `voucher_pay()` writes a row into
`dealer_payouts` / `distributor_payouts`, so "still owed" on every existing
screen keeps working off one definition. Those rows carry `voucher_id`, so a
transfer can be traced back to the claim it settled.

Nothing here recalculates commission. A voucher claims figures already frozen
onto each application when it arrived.

### The Friday run

`admin/cron/voucher-run.php`, run from the command line:

```
C:\xampp\php\php.exe C:\xampp\htdocs\manifold\admin\cron\voucher-run.php
```

Scheduled for **Friday 17:00** on Windows with:

```
schtasks /create /tn "Manifold voucher run" /sc weekly /d FRI /st 17:00 ^
  /tr "C:\xampp\php\php.exe C:\xampp\htdocs\manifold\admin\cron\voucher-run.php"
```

`--cycle=YYYY-MM-DD` raises against another date, to catch up a week that was
missed. The job refuses to run from a browser. What it does, in order:

1. For each **dealer** with a positive balance owed and no open voucher: raise
   one at `with_distributor`.
2. For each **distributor**: bundle every dealer voucher already approved, add
   their own balance if positive, and raise the bundle at `with_rf`.

Rules the run has to hold to:

- **Nothing owed, no voucher.** A zero or negative balance is skipped silently.
- **One open voucher per partner.** If last week's is still moving, this week
  adds nothing; the next run picks up whatever has accrued since.
- **Idempotent.** Running twice on the same Friday — a retry, a restarted
  server — produces no second voucher. The run is stamped with its cycle date
  and a cycle is processed once.
- **A missed Friday is not skipped.** If the machine was down, the next run
  catches up: the cycle is identified by date, not by when the job happened to
  fire.
- **Dealers awaiting approval** (§5) and switched-off partners raise nothing.
- **Manual raising stays available.** The Friday run is a convenience, not the
  only route; a partner can raise one any day, and the run then leaves them
  alone because they already have one open.

### Where each party sees it

| Screen | File | Shows |
|---|---|---|
| Dealer portal → Payouts | `dealer/payouts.php` | What they can claim, the button that raises it, and every claim they have made |
| Distributor portal → Payouts | `distributor/payouts.php` | Their dealers' claims to approve or turn down, their own claim, and the button that sends the bundle to R&F |
| R&F → Dashboard | `rf/index.php` | Three sections, which are the three jobs: to check, with the office, to pay |
| R&F → History | `rf/history.php` | Every settled voucher, with its reference or the reason it was refused |
| Admin → Commission | `admin/vouchers.php` | Bundles R&F has presented, every sale behind every line, and the button that funds them |

Both partner portals share one panel, `admin/partials/voucher-claim.php` — the
claim is the same on either side, and only who it goes to differs.

### What can go wrong

| Situation                                     | What happens                                                                                                                          |
| --------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| Partner has no bank details on file | The bundle shows "No bank details" against them and **Mark paid is disabled** — R&F sends it back instead. Paying it would record a transfer that never happened |
| Distributor turns down a dealer's line        | That line returns to the dealer with the reason. The rest of the bundle carries on                                                    |
| Office rejects a whole bundle                 | Every line in it returns to `rejected` with the reason. Nothing is paid                                                               |
| Office approves less than claimed             | The bundle is funded for the approved amount; unapproved lines return to the raiser rather than being silently dropped                |
| R&F pays some lines and not others            | The bundle stays open. It closes only when every line is `paid`                                                                       |
| A sale is refunded after being claimed        | The commission on it was already frozen. The correction is a negative adjustment on the _next_ voucher, never a rewrite of a paid one |
| A distributor sits on their dealers' vouchers | They age visibly in both portals, and the next Friday run does not raise duplicates                                                   |
| A dealer is deleted mid-flight                | Their sales survive (§6); the voucher line is settled or cancelled by the office, not left pointing at nothing                        |
| Two runs fire on one Friday                   | The second does nothing — the cycle is already stamped                                                                                |

### The data

| Table | Holds |
|---|---|
| `commission_vouchers` | One row per claim: `party_type`, `party_id`, `parent_id` (the bundle it rides in), `is_bundle`, `cycle_date`, `status`, `amount`, `reject_reason`, `payment_reference`, and three stamps — `raised_at`, `decided_at`, `paid_at` |
| `commission_voucher_lines` | The sales it is made of, unique on `(application_id, party_type, party_id)` |
| `commission_voucher_events` | Every move: from, to, who and when |
| `admin_users.role` | `admin` or `rf` |
| `dealer_payouts.voucher_id`, `distributor_payouts.voucher_id` | Which claim a transfer settled |

Migrations: `admin/migrations/2026-08-28-commission-vouchers.sql` and
`admin/migrations/2026-08-28-voucher-line-party.sql`.

### The code, by function

All in `admin/lib.php`.

| Function | Does |
|---|---|
| `voucher_claimable()` / `voucher_claimable_total()` | The sales a partner may claim, and their sum |
| `voucher_raise()` | A dealer's claim — voucher and lines written in one transaction |
| `voucher_bundle()` | A distributor's bundle: their own claim plus every dealer voucher they approved |
| `voucher_approve_dealer()` / `voucher_reject()` | The distributor's decision. Rejecting deletes the lines, freeing those sales for the next claim |
| `voucher_move_bundle()` | Moves a bundle and everything in it together — R&F to office, office to funded |
| `voucher_pay()` | R&F paying out: a payout row per partner, then the bundle closes |
| `voucher_run_cycle()` | The Friday run |
| `voucher_open_for()`, `voucher_lines()`, `voucher_events()`, `voucher_party()`, `voucher_has_bank()` | The reading side |

## 11. Quick reference — application statuses

| Status             | Admin reads               | Client reads               | Who moves it on                       |
| ------------------ | ------------------------- | -------------------------- | ------------------------------------- |
| `submitted`        | Waiting for approval      | Application received       | The office, by approving              |
| `booking_pending`  | Booking payment pending   | Booking payment due        | The client, by uploading a receipt    |
| `booking_review`   | Booking receipt — verify  | Booking payment submitted  | The office, by accepting or rejecting |
| `docs_pending`     | Finance documents — verify | Finance documents — verifying | The office, by verifying the documents. Turning them down keeps the status here: it records a reason on the row, emails it to the client and asks for corrected documents. Verifying afterwards clears the refusal. |
| `confirm_pending`  | Waiting on the client to confirm | Go ahead with delivery? | The client, by choosing to continue or cancel |
| `delivery_pending` | Delivery payment pending  | Delivery payment due       | The client, by uploading a receipt    |
| `delivery_review`  | Delivery receipt — verify | Delivery payment submitted | The office, by accepting or rejecting |
| `complete`         | Both payments verified    | Complete                   | Nobody — this is the end              |
| `cancelled`        | Cancelled — refund the booking | Cancelled — refund due | Nobody — the office transfers the refund |
| `rejected`         | Rejected                  | Not proceeding             | Nobody                                |
