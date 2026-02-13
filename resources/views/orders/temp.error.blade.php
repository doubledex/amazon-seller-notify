<h1>An Error Occurred</h1>

<p>{{ $error ?? 'Something went wrong. Please try again later.' }}</p>

@if (config('app.debug'))  Only show detailed error in debug mode
    <p>Error Details: {{ $exception ?? 'No exception details available.' }}</p>
@endif

<a href="{{ url('/') }}">Go Home</a>  {{-- Or redirect to a more appropriate page --}}