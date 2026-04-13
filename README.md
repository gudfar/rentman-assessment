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

Both methods use the **sweep line algorithm**: convert planning entries into `+quantity` at
start and `-quantity` at end events, sort by time, and walk through to find peak concurrent
load. O(n log n) vs O(n²) for naive pairwise comparison.

**`isAvailable`** — returns `true` if `peak_load + quantity <= stock` for the given equipment.

**`getShortages`** — single query for all equipment, groups by id, returns only those where
`peak_load > stock` as `[id => stock - peak_load]` (negative value).

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
