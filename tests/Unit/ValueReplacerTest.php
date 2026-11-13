<?php

use Novius\LaravelArtisanSearchReplace\Exceptions\UnprocessableValueException;
use Novius\LaravelArtisanSearchReplace\ValueReplacer;

it('refuses an empty search string', function () {
    new ValueReplacer('', 'foo');
})->throws(InvalidArgumentException::class);

it('replaces every occurrence in plain text, case-sensitively', function () {
    $replacer = new ValueReplacer('foo', 'bar');

    expect($replacer->replace('foo Foo foofoo'))->toBe('bar Foo barbar');
});

it('returns the very same value when nothing is replaced', function () {
    $replacer = new ValueReplacer('foo', 'bar');

    expect($replacer->replace('nothing here'))->toBe('nothing here');
});

it('never re-processes a replacement containing the search string', function () {
    $replacer = new ValueReplacer('foo', 'foofoo');

    expect($replacer->replace('a foo b'))->toBe('a foofoo b');
});

it('can replace with an empty string', function () {
    $replacer = new ValueReplacer('remove-me ', '');

    expect($replacer->replace('keep remove-me this'))->toBe('keep this');
});

it('replaces JSON-escaped occurrences in plain text', function () {
    $replacer = new ValueReplacer('https://old.test', 'https://new.test');

    expect($replacer->replace('<a href="https://old.test/a">x</a><script>var u = "https:\/\/old.test\/b";</script>'))
        ->toBe('<a href="https://new.test/a">x</a><script>var u = "https:\/\/new.test\/b";</script>');
});

it('replaces in JSON documents encoded with Laravel default flags', function () {
    $replacer = new ValueReplacer('https://old.test/é', 'https://new.test/"è"');
    $json = json_encode(['url' => 'https://old.test/é/page', 'other' => 'https://elsewhere.test']);

    $result = $replacer->replace($json);

    expect($result)->toBe(json_encode(['url' => 'https://new.test/"è"/page', 'other' => 'https://elsewhere.test']))
        ->and(json_decode($result, true))->toBe(['url' => 'https://new.test/"è"/page', 'other' => 'https://elsewhere.test']);
});

it('replaces in JSON documents with unescaped slashes and unicode', function () {
    $replacer = new ValueReplacer('https://old.test/é', 'https://new.test/è');
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    $json = json_encode(['url' => 'https://old.test/é/page'], $flags);

    expect($replacer->replace($json))->toBe(json_encode(['url' => 'https://new.test/è/page'], $flags));
});

it('keeps JSON documents valid when the replacement contains special characters', function () {
    $replacer = new ValueReplacer('name', 'a "quoted" \ value');
    $json = json_encode(['title' => 'my name']);

    expect(json_decode($replacer->replace($json), true))->toBe(['title' => 'my a "quoted" \ value']);
});

it('replaces in PHP serialized data and recomputes string lengths', function () {
    $replacer = new ValueReplacer('old.test', 'much-longer-domain.test');
    $data = ['url' => 'https://old.test', 'nested' => ['https://old.test/a', 42, null, true], 'old.test' => 'key untouched'];

    $result = $replacer->replace(serialize($data));

    expect(unserialize($result))->toBe([
        'url' => 'https://much-longer-domain.test',
        'nested' => ['https://much-longer-domain.test/a', 42, null, true],
        'old.test' => 'key untouched',
    ]);
});

it('replaces in a serialized string', function () {
    $replacer = new ValueReplacer('foo', 'foobar');

    expect($replacer->replace(serialize('a foo')))->toBe(serialize('a foobar'));
});

it('replaces in nested serialized data and JSON inside serialized data', function () {
    $replacer = new ValueReplacer('https://old.test', 'https://new.test');
    $data = [
        'inner' => serialize(['url' => 'https://old.test/a']),
        'json' => json_encode(['url' => 'https://old.test/b']),
    ];

    $result = unserialize($replacer->replace(serialize($data)));

    expect(unserialize($result['inner']))->toBe(['url' => 'https://new.test/a'])
        ->and(json_decode($result['json'], true))->toBe(['url' => 'https://new.test/b']);
});

it('replaces in the properties of allowed objects', function () {
    $replacer = new ValueReplacer('foo', 'bar');
    $object = new stdClass;
    $object->title = 'foo';
    $object->self = $object;

    $result = unserialize($replacer->replace(serialize(['object' => $object])));

    expect($result['object']->title)->toBe('bar')
        ->and($result['object']->self)->toBe($result['object']);
});

it('leaves objects of not allowed classes untouched', function () {
    $replacer = new ValueReplacer('foo', 'bar');
    $value = serialize(['text' => 'foo', 'object' => new ArrayObject(['foo', 'key' => 'foo'])]);

    $result = $replacer->replace($value);

    // Re-serialized as an incomplete object, but unserialized back identically
    expect(unserialize($result))->toEqual(['text' => 'bar', 'object' => new ArrayObject(['foo', 'key' => 'foo'])]);
});

it('does not modify serialized data when nothing is replaced', function () {
    $replacer = new ValueReplacer('foo', 'bar');
    // "foo" is only in an object of a not allowed class, and serialize(unserialize())
    // would not give back the same float representation
    $value = 'a:2:{i:0;d:0.10000000000000001;i:1;'.serialize(new ArrayObject(['foo'])).'}';

    expect($replacer->replace($value))->toBe($value);
});

it('refuses to process corrupted serialized data', function () {
    $replacer = new ValueReplacer('foo', 'bar');

    $replacer->replace('a:1:{i:0;s:10:"foo";}');
})->throws(UnprocessableValueException::class);

it('processes as plain text a value only looking like the start of serialized data', function () {
    $replacer = new ValueReplacer('foo', 'bar');

    expect($replacer->replace('s:3:"foo" is not serialized'))->toBe('s:3:"bar" is not serialized');
});

it('exposes the needles to search for', function () {
    $replacer = new ValueReplacer('https://old.test/é', 'x');
    $escapedE = '\\'.'u00e9';

    expect($replacer->needles())->toEqualCanonicalizing([
        'https://old.test/é',
        'https:\/\/old.test\/'.$escapedE,
        'https://old.test/'.$escapedE,
        'https:\/\/old.test\/é',
    ]);
});
