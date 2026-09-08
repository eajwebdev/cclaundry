@props(['ring' => true])

{{--
    The Cane & Cotton logo file is a square PNG with an opaque backdrop behind
    its circular mark. Rendering it raw puts a black box (or, once rounded, a
    black ring) on every surface. Clipping to a circle and scaling slightly past
    the frame trims the backdrop away, so the mark sits cleanly on any
    background. Size it from the call site, e.g. class="h-11 w-11".
--}}
<span {{ $attributes->class([
    'block shrink-0 overflow-hidden rounded-full bg-cream',
    'ring-1 ring-primary/20' => $ring,
]) }}>
    <img src="{{ $appBusinessLogo }}"
         alt="{{ $appBusinessName ?: config('app.name') }}"
         class="h-full w-full scale-[1.08] object-cover">
</span>
