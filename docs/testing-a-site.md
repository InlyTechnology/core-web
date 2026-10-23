# Testing a site

```php
use Inly\CoreWeb\Testing\FakesCore;

class FacilityTest extends TestCase
{
    use FakesCore;

    public function test_a_ping_refreshes_the_facility(): void
    {
        $this->fakeCore([
            'core.test/api/v1/objects/facility/*' => self::coreObjectById('facility', $resources),
            'core.test/api/v1/objects/facility*' => self::coreList($resources),
        ]);

        $this->withPingSecret()->receivePing(['type' => 'facility', 'id' => '1', 'public_id' => $uuid])->assertStatus(202);
    }
}
```

In a site's base `TestCase`, `config(['core-web.snapshots.store' => null])`
keeps snapshots in the test's array store.

## Sparse fieldsets

Every field Core sends costs its value in `attributes`, and under full row
meta its description in `meta.schema` as well, so a pull should name the
fields its documents read
(`fields[_]=title,slug`, and `fields[{table}]` for an included type). To keep
that list honest, run a pull against the fake twice, once whole and once
narrowed as Core narrows it, and compare the documents:

```php
$whole = FacilitySnapshot::pull(CoreClient::fromConfig());
$this->answerSparseFieldsets(['manufacturer' => 'manufacturers']); // included type => table
$this->assertSame($whole, FacilitySnapshot::pull(CoreClient::fromConfig()));
```

`answerSparseFieldsets()` narrows every later Objects API answer to the
fieldsets its request names (a request naming none gets the whole answer), and
`requestedFieldsets($request)` reads them off a recorded request. A builder
that starts reading a field its pull does not ask for then fails the test, as
long as the fixture carries that field.

## Compact row meta

`answerRowMeta()` gives every later Objects API row the meta Core answers: a
read asking for `meta=compact` gets the row's identity meta alone, any other a
`meta.schema` entry per attribute and `meta.object` besides it. Set
`$this->fakeCoreCompactsMeta = false` to answer full meta whatever is asked, as
a Core before 9.0 does. A pull that builds the same documents from both reads
nothing compact leaves out:

```php
$this->answerRowMeta();
$this->fakeCoreCompactsMeta = false;
$full = FacilitySnapshot::pull(CoreClient::fromConfig());
$this->fakeCoreCompactsMeta = true;
$this->assertSame($full, FacilitySnapshot::pull(CoreClient::fromConfig()));
```

`requestedRowMeta($request)` reads `compact` or `full` off a recorded request.
Core's display strings (`meta.formatted`) are not faked.
