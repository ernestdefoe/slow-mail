<x-mail::plain.notification>
<x-slot:body>
{{ $translator->trans('ernestdefoe-slow-mail.email.arrived.body', ['{name}' => $blueprint->letter->sender?->display_name ?? '']) }}

{{ $translator->trans('ernestdefoe-slow-mail.email.arrived.open') }}: {{ $url->to('forum')->route('slow-mail.letter', ['id' => $blueprint->letter->id]) }}
</x-slot:body>
</x-mail::plain.notification>
