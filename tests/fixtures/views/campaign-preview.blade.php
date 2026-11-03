<html><body>
<h1 @coreField('title', true)>{{ $campaign['title'] }}</h1>
@foreach ($campaign['blocks'] ?? [] as $block)
    <section @coreBlock($loop->index)>{{ $block }}</section>
@endforeach
<p>{{ $others }}</p>
</body></html>
