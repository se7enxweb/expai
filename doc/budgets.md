# Budgets of queued requests

Requests that run in the job queue spend money without anybody watching. Two budgets keep them in bounds:

| Budget | Where it is set | What counts against it |
| --- | --- | --- |
| **monthly site budget** | `[Budgets] SiteMonthly` (in `[Pricing] Currency`); when empty `[Limits] MaxCostPerSitePerMonth` | the cost of every request of the month (panel, field actions and queue: `expai_usage_log`) plus the reservations of the queued requests that have not been answered |
| **job group budget** | `budget_micros` of the expqueue group (the producer sets it; `[Budgets] DefaultGroupBudget` when it does not) | the settled cost of the group's requests (`spent_micros`) plus the group's reservations |

When neither is set, nothing is held; the costs are still estimated, reserved and shown.

## Reservation, hold, resume

1. **Estimate** when a request is queued: input tokens from the text (`CharsPerToken`, default 3.5 characters per
   token), output tokens from the producer's `est_output` or `OutputShareOfMax` x `max_tokens`, priced with the model's
   prices ([usage-tracking.md](usage-tracking.md)) x the lane's price factor (batch 0.5, fast 2.0).
2. **Reserve** the estimate x `ReserveFactor` (1.25) on the job (`cost_reserved_micros`).
3. **Hold** at once when the reservation does not fit a budget: the job's state is `held`, its error class `budget`,
   its last error says which budget and the figures ("the monthly site budget of $50.00 is reached ($49.10 spent,
   $0.80 reserved, $0.30 needed)").
4. **Check again** right before the call: other requests may have cost more than they reserved. A job that does not
   fit any more is held without calling the provider.
5. **Settle** when the usage is recorded: the reservation becomes the job's actual cost (`cost_actual_micros`) and
   counts for its group (`spent_micros`).
6. **Resume**: the cron part `expai` (`expai_queue.php`, every run), `./console ext:expai:queue resume` and the button
   "Resume held jobs that fit the budget" on `expai/budgets` (policy `expai/settings`) put held jobs back into the
   queue, highest priority first, as long as each fits. A new month, a raised budget or requests that cost less than
   reserved free the money.

Resuming a held group on the expqueue pages puts its jobs back too; each is checked again before its call and held
again when it still does not fit.

A translation by a machine translation service (job `expai.translation.run`, [translation-providers.md](translation-providers.md))
goes through the same steps: its estimate is the characters to translate x `[TranslationProvider_<id>]
PricePerMChars`, reserved x `ReserveFactor`, checked again before the request and settled with the cost of the billed
characters. The site budget and job group budgets cover it like a model request.

The `[Limits]` of the panel still apply to queued requests ([configuration.md](configuration.md#limits-optional-limits)):
a limit that is reached holds the job the same way.

## Settings

```ini
[Budgets]
SiteMonthly=50
DefaultGroupBudget=5
ReserveFactor=1.25
CharsPerToken=3.5
OutputShareOfMax=0.5
```

A producer sets a group budget when it creates the group:

```php
$group = expQueue::createGroup( 'Translate the news into German', 'expai.request', array( 'budget_micros' => expAiBudget::micros( 12.50 ) ) );
```

## Where the figures are

- The dashboard (users with `expai/log`): "Budget this month" with a bar (spent + reserved of the budget), what is
  left, when it resets, and how many jobs are held.
- `expai/budgets`: the same, every job group with an expai job or a budget (budget, spent, reserved, held), the held
  jobs, the lanes and the provider batches.
- `./console ext:expai:queue budget`.
- The usage log (`expai/log`, CSV) prices each request with its billed price factor (`price_factor`): a batch request
  at 50 %, a fast one at 200 %.

## Amounts

All amounts are micros: millionths of the currency unit (`expAiBudget::micros( 0.25 )` = 250000). The estimate is not
an invoice; the provider's invoice is authoritative.
