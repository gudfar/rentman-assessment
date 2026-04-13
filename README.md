# Assessment repo

To get the full instructions the first step is to get this docker setup running. If you want to go to the instructions
directly check the file under `app/instructions`


## Get it running

These instructions assume docker is already running.

```
# Give execution permissions to composer.sh (windows and linux)
chmod 755 composer.sh

# install the php autoloader
./composer.sh install  

# run the environment
docker-compose up

```

After running these commmands, these urls are available:

- http://localhost:7080/availability — availability form (port changed, see note below)
- http://localhost:7001/ phpMyAdmin

## Implementation notes

### Approach

Both methods are built around the **sweep line algorithm**. Instead of checking every point
in time, we collect two events per planning entry — a `+quantity` at `start` and a `-quantity`
at `end` — sort them by timestamp, and walk through them to find the peak concurrent load.

This gives O(n log n) complexity where n is the number of overlapping planning entries,
instead of O(n²) for a naive pairwise comparison.

**`isAvailable`** fetches all planning entries for the requested equipment that overlap the
timeframe, finds the peak load, and returns `true` if `peak + requested_quantity <= stock`.

**`getShortages`** fetches all equipment with overlapping planning entries in a single query,
groups by equipment, and for each calculates the peak load. Only equipment where
`peak > stock` is included in the result, with the shortage as a negative value.

### Database index

Added a composite index on the `planning` table:

```sql
INDEX idx_planning_equipment_start_end (equipment, start, end)
```

`equipment` is first because it is the most selective filter. `start` and `end` allow the
database to evaluate the overlap condition directly from the index without a full table scan.

### Production considerations

**Race conditions** — `isAvailable` is a read-only check. In a real system, concurrent
requests could both read "available" and both insert, exceeding stock. The correct fix is
`SELECT ... FOR UPDATE` inside a transaction at the booking layer, or optimistic locking.

**Caching** — for frequently requested date ranges, results could be cached in Redis with
a key like `availability:{equipment_id}:{start}:{end}` and invalidated on any `planning`
INSERT or UPDATE.

**Partitioning** — with millions of planning rows, partitioning the `planning` table by date
range would ensure queries only scan the relevant partition.

**Read replicas** — both methods are read-only and can be routed to a read replica to keep
write load off the primary database.

**Precomputed availability** — for very high read traffic, a background job could maintain
a precomputed `equipment_availability` table updated on each planning change, reducing
`isAvailable` to a single indexed lookup.

### Known limitations

- No input validation — intentionally omitted as per the assessment instructions
- Race conditions are not handled at this layer — see Production considerations above
