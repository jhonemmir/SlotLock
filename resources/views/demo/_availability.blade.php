<div id="availability" @if (! empty($oob)) hx-swap-oob="outerHTML" @endif>
    @include('demo._grid')
</div>
