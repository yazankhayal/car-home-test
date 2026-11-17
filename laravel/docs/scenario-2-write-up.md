# Scenario 2: Slow orders endpoint

## Problem

`GET /api/orders` had an N+1 query problem. It loaded every order with `Order::all()`. The loop then loaded each order's customer and items one by one.

With 1,000 orders, that was 2,001 queries: one for the orders, then one for each customer and one for each set of items.

## Fix

The controller now eager loads both relations:

```php
Order::with(['customer', 'items'])->get();
```

This runs 3 queries, whatever the number of orders: orders, customers, then items.

## Result

Measured with curl on the same seeded data (1,000 orders):

| | Queries | Response time |
|---|---|---|
| Before | 2,001 | 1.06s |
| After | 3 | 0.24s |

That is about 4.5 times faster. The gap should be larger in production, where each query also waits on the network.

## Tests

A new test seeds 20 orders and counts the queries. It ran 41 queries before the fix and 3 after. It fails if the N+1 comes back.

The existing tests still pass.

## Not done

- **Pagination.** It changes the response from a plain array to `{data, links, meta}`. That would break the existing test and any client that expects an array.
- **Caching.** The cause was the queries, so I fixed those first. Caching would have hidden the problem, not solved it.
