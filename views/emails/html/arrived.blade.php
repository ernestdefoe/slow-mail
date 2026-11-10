<x-mail::html.notification>
    <x-slot:body>
        {{-- Text only, escaped: a display name never becomes markup. Not a word of the letter. --}}
        <p>{{ $translator->trans('ernestdefoe-slow-mail.email.arrived.body', ['{name}' => $blueprint->letter->sender?->display_name ?? '']) }}</p>
        <p><a href="{{ $url->to('forum')->route('slow-mail.letter', ['id' => $blueprint->letter->id]) }}">{{ $translator->trans('ernestdefoe-slow-mail.email.arrived.open') }}</a></p>
    </x-slot:body>
</x-mail::html.notification>
