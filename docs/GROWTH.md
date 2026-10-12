# OSAPS growth features

## Works straight away (no extra accounts)
- **Aṣọ-ẹbí group orders** — on any fabric, "Start aṣọ-ẹbí". The organiser sets yards per guest, expected guests, a deadline and delivery (to each guest, or the organiser collects). One link to share on WhatsApp; each guest pays their own share through Paystack; the page shows "12 of 40 paid" and first names (only of guests who agree). If the organiser first agreed a bulk price with the vendor ("Name your price" for the total yards), guests get that price until the agreed yards are used up.
- **Real photos and video** — vendors add up to 6 photos and one short video per fabric (Vendor → Listings). Files are checked by their contents, renamed, and stored in `public_html/public_html/uploads/`, which refuses to run scripts.
- **Buyer protection** — the vendor's payout is *held* until the buyer confirms receipt, or 3 days after the vendor marks it delivered (`SHOP_AUTO_COMPLETE_DAYS`). Admins see what to pay at `GET /api/v1/admin/payouts` and mark it paid.
- **Notifications** — the bell in the header: new offers, counters, deals, payments, dispatch, aṣọ-ẹbí payments.
- **Deliver when I land** — diaspora buyers choose an arrival date and a Nigerian address (hotel or family house).
- **Measurements** — Account → My measurements, with a drawn guide; shared with the tailor a customer hires (if they allow).
- **Live from the market** — vendors list TikTok / Instagram / YouTube / Facebook live sessions (Vendor → Go live) with featured fabrics; YouTube lives play on the page.

## Switch on when ready
| Feature | What you need | .env |
|---|---|---|
| Online payment | Paystack account | `PAYSTACK_SECRET_KEY=sk_test_…` (see SHOP.md) |
| Approximate £/$/€ prices | Today's rates (naira per unit) | `FX_GBP=…`, `FX_USD=…`, `FX_EUR=…`, `FX_CAD=…`, `FX_UPDATED="12 Oct 2026"` |
| Ship abroad | Your courier's price per parcel, in kobo | `SHIP_UK_MINOR=…`, `SHIP_US_MINOR=…`, `SHIP_EU_MINOR=…`, `SHIP_CA_MINOR=…`, `SHIP_AFRICA_MINOR=…`, `SHIP_OTHER_MINOR=…` (0 = not offered) |
| WhatsApp updates | Meta WhatsApp Business (Cloud API) + an approved template | `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_TEMPLATE=osaps_update`, `WHATSAPP_TEMPLATE_LANGUAGE=en` |
| "See it on me" try-on | OpenAI API key with image access | `TRYON_PROVIDER=openai`, `TRYON_OPENAI_API_KEY=…` |

### WhatsApp template
Create a **Utility** template named `osaps_update`, language English, body:
`Hello {{1}}, {{2}} Open it here: {{3}}`
Only people who tick "Send me updates on WhatsApp" in Account (with a phone number) get messages.

### Try-on
The customer's photo is sent to the AI provider with the fabric (its real photo if the vendor uploaded one) and the picture comes straight back; OSAPS stores neither. Each person gets 5 a day (`TRYON_DAILY_LIMIT`), the whole site 200 (`TRYON_DAILY_LIMIT_GLOBAL`). Results are labelled as an AI preview. Test it yourself before announcing it: image costs are charged to your OpenAI account.

### International payments
Paystack charges in naira. For cards issued abroad, ask Paystack to enable international payments on your account.

## Admin
There is no admin screen yet. To make someone an admin, set their `role` to `admin` in the `users` table (phpMyAdmin). Admin API: `GET /api/v1/admin/payouts`, `POST /api/v1/admin/fabric-orders/{reference}/paid-out`.

## Not verified here
The Laravel side was written without being run (Composer was blocked in the build environment). Rules are covered by `tests/Unit/Domain/GrowthRulesTest.php` and `ShopRulesTest.php` (passing). Feature tests `tests/Feature/Api/GrowthFeaturesTest.php` and `ShopFlowTest.php` should be run once Composer is available. WhatsApp and the try-on provider were never called with real keys.
