<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title> Email Verification with OTP</title>
</head>
<body>
    <h1>Thanks for registering with us !</h1>
    <p>{{ $user_email}} </p>
    <p>Here's your verification code : {{ $validToken}}</p>
</body>
</html>