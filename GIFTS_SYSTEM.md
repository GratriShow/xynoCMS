# Gifts System (Cadeaux)

## Overview

The gifts system allows administrators to distribute promotional gifts to users. Gifts can be:
- **Coupons**: Discounts applied to Stripe (1-100%)
- **Credits**: Days added to active subscriptions

## Database Schema

### `gifts` Table
Main gift definition table.

```sql
CREATE TABLE gifts (
    id INT PRIMARY KEY AUTO_INCREMENT,
    type ENUM('coupon', 'credit') NOT NULL,  -- Gift type
    description VARCHAR(255) NOT NULL,        -- Display name (e.g., "Black Friday 50%")
    value INT NOT NULL,                       -- For coupon: percent. For credit: days
    single_code BOOLEAN DEFAULT FALSE,        -- true = single code for all, false = unique per user
    code VARCHAR(50),                         -- Single code (if single_code = true)
    expires_at DATETIME NOT NULL,             -- When gift expires
    created_by INT NOT NULL,                  -- Admin who created it
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
    INDEX idx_expires (expires_at),
    INDEX idx_type (type)
);
```

### `gift_codes` Table
Tracks unique codes generated for users (when `single_code = false`).

```sql
CREATE TABLE gift_codes (
    id INT PRIMARY KEY AUTO_INCREMENT,
    gift_id INT NOT NULL,
    code VARCHAR(50) NOT NULL UNIQUE,
    redeemed_by INT,                         -- User who redeemed
    redeemed_at DATETIME,                    -- When redeemed
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (gift_id) REFERENCES gifts(id) ON DELETE CASCADE,
    FOREIGN KEY (redeemed_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_code (code),
    INDEX idx_gift (gift_id),
    INDEX idx_redeemed (redeemed_by)
);
```

### `gift_recipients` Table
Tracks who was sent which gift.

```sql
CREATE TABLE gift_recipients (
    id INT PRIMARY KEY AUTO_INCREMENT,
    gift_id INT NOT NULL,
    user_id INT,
    email VARCHAR(255) NOT NULL,
    code VARCHAR(50),                        -- The code sent to this user
    sent_at DATETIME,                        -- When email was sent
    redeemed_at DATETIME,                    -- When gift was redeemed
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (gift_id) REFERENCES gifts(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_gift (gift_id),
    INDEX idx_user (user_id),
    INDEX idx_email (email),
    INDEX idx_redeemed (redeemed_at)
);
```

### `gift_audit_log` Table
Audit trail for all gift-related actions.

```sql
CREATE TABLE gift_audit_log (
    id INT PRIMARY KEY AUTO_INCREMENT,
    admin_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,             -- created, sent, redeemed
    gift_id INT,
    user_id INT,
    details JSON,                            -- Additional data
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE RESTRICT,
    FOREIGN KEY (gift_id) REFERENCES gifts(id) ON DELETE SET NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_action (action),
    INDEX idx_created (created_at)
);
```

## Admin Interface

### `/admin/gifts.php`
Main gifts management page.

**Features:**
- List all gifts with creation/expiration dates
- Create new gifts (coupon or credit)
- Choose between single code and unique codes per user
- View quick stats (codes generated, sent count)

**Form Fields:**
- **Type**: Coupon or Credit
- **Value**: Percentage (1-100) for coupons, days for credits
- **Description**: Display text for users
- **Expiration Date**: When gift becomes unavailable
- **Code Type**: Single code (shared) or unique codes (per user)

### `/admin/gift_detail.php?id={id}`
Detailed view of a single gift.

**Sections:**
- Gift information (type, value, code, expiration)
- Generated codes list (if unique codes)
- Recipients and redemption status
- Audit log of all actions

### `/admin/gift_send.php?id={id}`
Send a gift to recipients.

**Recipient Types:**
- **All Users**: Send to all non-deleted users
- **Active Launchers**: Send only to users with an active launcher
- **Custom List**: Paste email addresses (one per line)

**Process:**
1. Validate recipient list
2. Generate unique codes if needed
3. Insert records into `gift_recipients`
4. Send emails with codes
5. Log action in audit

## Public Interface

### `/gifts.php`
User-facing gifts page.

**Features:**
- Display available (non-expired) gifts
- Show type, value, and expiration
- Input field to enter/redeem code
- Success/error notifications

**Redemption Logic:**
1. Validate code exists and not already redeemed
2. Find matching gift
3. Update `gift_recipients` with `redeemed_at`
4. Update `gift_codes` if unique code
5. Trigger gift application (Stripe coupon or subscription credit)

## API Endpoints

### POST `/api/gifts.php`
Redeem a gift via API (for launcher integration).

**Request:**
```json
{
  "code": "GIFT123ABC"
}
```

**Response (Success):**
```json
{
  "ok": true,
  "gift": {
    "id": 1,
    "type": "coupon",
    "value": 50,
    "description": "Black Friday 50%"
  }
}
```

**Response (Error):**
```json
{
  "error": "Invalid or expired code"
}
```

## Integration Hooks

### Stripe Coupon Application
When a **coupon** gift is redeemed:

1. Extract coupon percentage from `gifts.value`
2. Create Stripe coupon (if not already created)
3. Apply coupon to user's account
4. Optional: Send confirmation email

**TODO:** Implement Stripe API integration in redemption logic.

### Subscription Credit Application
When a **credit** gift is redeemed:

1. Extract days from `gifts.value`
2. Find user's active subscription
3. Calculate new expiration: `current_expires_at + {value} days`
4. Update subscription in database
5. Update Stripe subscription end date (if applicable)
6. Send confirmation email

**TODO:** Implement subscription extension logic.

## Code Generation

### Single Code (Shared)
For campaigns like "BLACKFRIDAY2026":
- One code for all users
- Stored in `gifts.code`
- Unlimited uses
- Tracks redemption in `gift_recipients` only

### Unique Codes
For personalized gifts:
- Generated per user: `GIFT{8-char-hex}`
- Stored in `gift_codes` table
- One-time use per code
- Tracks redemption in both `gift_codes` and `gift_recipients`

## Email Templates

### Gift Notification Email
Sent when a gift is distributed to a user.

**Template Variables:**
- `{gift_description}` - e.g., "Black Friday 50%"
- `{gift_value}` - e.g., "50" or "7"
- `{gift_type}` - "coupon" or "credit"
- `{code}` - The code to redeem
- `{expires_at}` - Expiration date

**Example HTML:**
```html
<p><strong>{gift_description}</strong></p>
<p>You've received a {gift_type} for <strong>{gift_value}</strong>.</p>
<p>Code: <code>{code}</code></p>
<p>This offer expires on {expires_at}.</p>
```

## Workflow Examples

### Example 1: Black Friday Campaign
1. Admin creates gift:
   - Type: `coupon`
   - Value: `50` (percent)
   - Description: "Black Friday 50% off"
   - Code: `BLACKFRIDAY2026` (single code)
   - Expires: 2026-12-01

2. Admin sends to all active launchers
3. Code is shared via email/newsletter
4. Users enter code to get 50% discount coupon

### Example 2: Anniversary Reward
1. Admin creates gift:
   - Type: `credit`
   - Value: `7` (days)
   - Description: "Anniversary 7 Days Free"
   - Unique codes (auto-generated)
   - Expires: 2026-06-30

2. Admin sends to selected users
3. Each user gets unique code in email
4. User redeems code → 7 days added to subscription

## Admin Workflow

### Creating a Gift
```
1. Navigate to /admin/gifts.php
2. Fill form:
   - Type: Coupon or Credit
   - Value: 1-100 (coupon) or number (credit)
   - Description: Marketing name
   - Date: Expiration date
   - Code: Single code or unique per user
3. Submit
4. Gift is created and appears in list
```

### Sending a Gift
```
1. Navigate to /admin/gifts.php
2. Click "Envoyer" on target gift
3. Select recipient type:
   - All users
   - Active launchers
   - Custom email list (CSV)
4. Submit
5. Emails sent, codes generated
6. View details in gift_detail.php
```

### Tracking Redemptions
```
1. Navigate to /admin/gift_detail.php?id={gift_id}
2. View:
   - Generated codes and redemption status
   - Recipients and redemption dates
   - Audit log of all actions
```

## Technical Notes

- All code generation uses `bin2hex(random_bytes(5))` for security
- Expired gifts are automatically excluded from public interface
- Soft-deleted users still appear in recipient tracking (audit trail)
- Single codes are unlimited-use until expiration
- Unique codes are single-use only
- Redemption is idempotent via `gift_recipients` unique constraint

## Future Enhancements

- [ ] CSV import for recipient lists
- [ ] Batch code generation and download
- [ ] Stripe coupon auto-creation
- [ ] Subscription credit application
- [ ] Email template customization
- [ ] A/B testing with different gift values
- [ ] Newsletter integration for automatic distribution
- [ ] Analytics: redemption rate, conversion tracking
