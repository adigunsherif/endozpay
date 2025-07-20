# EndozPay Gateway for WooCommerce

**EndozPay** is a WooCommerce payment gateway plugin that allows customers to pay using **Endoz OpenBanking**. It supports both traditional and block-based WooCommerce checkouts.

---

## ✨ Features

- ✅ Seamless OpenBanking integration
- 🔐 Secure token-based payment initiation
- 🔁 Webhook support for automatic transaction updates
- 🧱 Full compatibility with WooCommerce Checkout Blocks
- ⚙️ Configurable settings via WooCommerce admin

---

## 📦 Installation

1. Download or clone this repository:

2. Copy the plugin folder into your WordPress installation:
  ``` /wp-content/plugins/endozpay/ ```

3. Activate EndozPay Gateway

## ⚙️ Configuration
1. Go to WooCommerce > Settings > Payments

2. Click on EndozPay

3. Fill in the required fields:


## 🧠 How It Works
1. Checkout Selection: Customer selects EndozPay at checkout.

2. Payment Redirection: They’re redirected to the Endoz API to complete payment.

3. Post-Payment Return: Endoz redirects the user back to the WooCommerce order-received page with a URL like:

4. Thank You Page Logic:
  a. If paymentStatus is not COMPLETED or PROCESSING, WooCommerce redirects customer back to retry checkout.
  b. If COMPLETED/PROCESSING, the order is marked as 'on-hold'.

5. Webhook:
  a. Endoz also calls a webhook URL on your store to confirm payment.
  b. Webhook payload will update order status regardless of thank-you page visit.
