<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Unsubscribed</title>
</head>
<body>
    <h1>You've been unsubscribed</h1>
    <p>You will no longer receive emails from this list.</p>

    @if ($listId !== null)
        <p>
            <a href="{{ route('nettmail.unsubscribe.all', $token) }}">Unsubscribe from all emails</a>
        </p>
    @endif
</body>
</html>
