{{--
    laranail/settings::mail.alert -- the SettingsAlert mail (markdown). The notification hands it
    identifiers only (headline, label => value lines, footer), never a setting's value, and the
    text is already translated. Publish with --tag=laranail::settings-views to restyle it.
--}}
<x-mail::message>
# @lang('Hello!')

{{ $headline }}.

@foreach ($lines as $label => $value)
{{ $label }}: {{ $value }}

@endforeach
{{ $footer }}

@lang('Regards,')<br>
{{ config('app.name') }}
</x-mail::message>
