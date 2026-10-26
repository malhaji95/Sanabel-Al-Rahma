import './bootstrap'

/*
 | Alpine is not imported here, and must not be.
 |
 | Livewire ships its own Alpine and starts it with its plugins bound. A second
 | copy started from this file walked the page first and left every node marked
 | as initialised, so Livewire's own Alpine found nothing left to initialise:
 | the page registered the top-level component and silently skipped every
 | component nested inside it. The console said only «Detected multiple
 | instances of Alpine running».
 |
 | What that cost on screen: the «إضافة» box under each family on the funding
 | list is a nested component. Its wire:submit was never bound, so the browser
 | submitted the form itself — a plain GET to the same page. The page reloaded,
 | the donor was thrown to the top of the list, and nothing reached the basket.
 |
 | Alpine is still available as window.Alpine, from Livewire.
 */
