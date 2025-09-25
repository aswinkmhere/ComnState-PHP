<?php
session_start();
if (isset($_SESSION['user'])) {
    header("Location: dashboard.php");
    exit;
}

$db = new PDO('sqlite:../db/app.db');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $stmt = $db->prepare("SELECT * FROM users WHERE username=? AND password=?");
  $stmt->execute([$_POST['username'], $_POST['password']]);
  $user = $stmt->fetch(PDO::FETCH_ASSOC);

  if ($user) {
     $_SESSION['user'] = $user['username'];
    if($user['isAdmin'] == 1){
       $_SESSION['isAdmin'] = 1;
       header("Location: admin_map.php");
       exit;
    }
    header("Location: dashboard.php");
    exit;
  } else {
    $error = "Invalid login";
  }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/bootstrap.min.css"/>
     <link rel="stylesheet" href="css/style.css"/>
</head>
<body>
    <a href="#" class="ribbon">Dagger Website</a>
    <a href="index.php" class="ribbon-cc">
        Comn State
    </a>
    <div class="container">
        <div class="row justify-content-center">
            
            <div class="login-card">
                <h3 class="fw-bold">Login</h3>
                <?php if (!empty($error)) : ?>
                    <div class="alert alert-danger text-center" role="alert">
                        <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>
                <form method="post">
                    <div class="mb-3">
                        <input type="text" name="username" class="form-control form-control-lg" placeholder="Username" required/>
                    </div>
                    <div class="mb-3">
                        <input type="password" name="password" class="form-control form-control-lg" placeholder="Password" required/>
                    </div>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary btn-lg">Login</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>