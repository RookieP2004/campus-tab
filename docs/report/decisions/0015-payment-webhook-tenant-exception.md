# 0015 — The payment webhook: a second tenant-scope exception

Date: 2026-11-28 · Phase 8 · Status: accepted

**Context.** Decision 0005 allows exactly one way around the tenant scope: loading the owner of a sign-in token. A payment gateway's webhook (`POST /api/v1/webhooks/payments/{gateway}`) has no signed-in user, so no school can be taken from a user. Yet the signature has to be checked with the **school's own** webhook secret, which lives inside that school's data. The school must be found before anything in the request can be trusted.

**Decision.**

1. **The school is found from our own order record, never from the request's word.** `OnlinePayments::webhook` reads only the gateway's order id from the body, then, inside `TenantContext::bypass('payment webhook: find the school from our order record (decision 0015)', …)`, runs exactly one query: `gateway_orders` where provider and order id match, returning only `tenant_id`. The order was created by our server at checkout, inside that school.
2. **Everything else runs inside that school** (`TenantContext::run`): the gateway account, the signature check on the **raw body** (HMAC-SHA256 with the school's encrypted webhook secret), the amount and currency check against the order, and the payment.
3. **A wrong guess gets nothing.** An unknown order id is 404; a body signed with any other school's secret fails the signature (401) because the order belongs to its own school; a body with no order id is 400. Nothing is written before the signature passes, except an audit entry of the rejection.
4. **Idempotent.** The payment and a `gateway_events` record (unique on school, provider, gateway payment id and outcome) are written in one transaction; a replay answers 200 "replayed" and changes nothing.
5. The bypass is not used anywhere else in Fees. A new bypass still needs its own decision record.

**Consequences.**

- The lookup query has no `tenant_id`, so it cannot lead with it. The convention "every index starts with `tenant_id`" is kept (`tenant_id, provider, order_id`), so this query scans `gateway_orders`. That is fine for the pilot; if volume grows, a platform-level order routing collection is the next step (it would need this record updated).
- Tests: `FeesTest` "finds the school from the order record only" (another school's secret, unknown order, no order id) and the replay test; the tenant query guard passes for the whole suite.
