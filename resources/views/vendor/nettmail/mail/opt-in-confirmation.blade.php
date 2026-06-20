<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Confirm your subscription</title>
</head>
<body>
    <p>Hi {{ $contact->first_name ?: $contact->email }},</p>
    <p>Please confirm your subscription to {{ $list->name }} by clicking the link below.</p>
    <p><a href="{{ $confirmUrl }}">Confirm subscription</a></p>
</body>
</html>
