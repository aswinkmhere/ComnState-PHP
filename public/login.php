<?php
session_start();
$db = new PDO('sqlite:../db/app.db');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $db->prepare("SELECT * FROM users WHERE username=? AND password=?");
  $stmt->execute([$_POST['username'], $_POST['password']]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($user) {
    $_SESSION['user'] = $user['username'];
    header("Location: dashboard.php");
    exit;
  } else {
    $error = "Invalid login";
  }
}
?>

<form method="post">
  <h3>Login</h3>
  <?php if (!empty($error)) echo "<p>$error</p>"; ?>
  <input type="text" name="username" placeholder="Username"/><br>
  <input type="password" name="password" placeholder="Password"/><br>
  <button type="submit">Login</button>
</form>
