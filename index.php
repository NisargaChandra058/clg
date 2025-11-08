<?php
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>College Exam Section</title>
    <style>
/* General Styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Arial', sans-serif;
}

body {
    background-color: #f4f4f9;
    font-size: 16px;
    color: #333;
    line-height: 1.6;
    overflow-x: hidden;
}

h1, h2, h3, h4 {
    color: #333;
}

a {
    text-decoration: none;
    color: #3498db;
}

a:hover {
    color: #2980b9;
}

/* Background Video */
.video-background {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: -1;
}

#bg-video {
    object-fit: cover;
    width: 100%;
    height: 100%;
}

/* Main Content */
.main-content {
    position: relative;
    z-index: 1;
    text-align: center;
    padding: 80px 20px;
}

.navbar {
    background-color: rgba(0, 0, 0, 0.5);
    padding: 15px;
    position: fixed;
    width: 100%;
    top: 0;
    left: 0;
    text-align: center;
}

.navbar a {
    margin: 0 15px;
    color: white;
    font-size: 18px;
    padding: 8px 16px;
    border-radius: 5px;
}

.navbar a:hover {
    background-color: #2980b9;
}

/* Heading and Content */
.content h1 {
    font-size: 3rem;
    color: #fff;
    margin-bottom: 20px;
}

.content p {
    font-size: 1.2rem;
    color: #fff;
}

/* Buttons */
.buttons {
    margin-top: 40px;
}

.buttons .button {
    background-color: #3498db;
    color: white;
    padding: 15px 30px;
    font-size: 1.2rem;
    border-radius: 5px;
    margin: 10px;
    display: inline-block;
    text-align: center;
}

.buttons .button:hover {
    background-color: #2980b9;
}

/* Loading Spinner */
.loading {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    display: none;
}

.loading .spinner {
    border: 8px solid #f3f3f3;
    border-top: 8px solid #3498db;
    border-radius: 50%;
    width: 60px;
    height: 60px;
    animation: spin 2s linear infinite;
}

@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

/* Responsive Styles */
@media (max-width: 768px) {
    .navbar {
        text-align: left;
        padding: 10px;
    }

    .navbar a {
        display: block;
        margin: 5px 0;
    }

    .main-content {
        padding: 40px 15px;
    }

    .content h1 {
        font-size: 2rem;
    }

    .buttons .button {
        padding: 12px 20px;
    }
}

</style>
</head>
<body>
    <!-- Background Video -->
    <div class="video-background">
        <video autoplay muted loop id="bg-video">
            <source src="video/back.mp4" type="video/mp4">
            Your browser does not support the video tag.
        </video>
    </div>

    <!-- Main Content -->
    <div class="main-content">
        <div class="navbar">

        </div>

        <div class="content">
            <h1>Welcome to the AMC College Exam Section</h1>
            <p>Prepare your question papers and manage exam schedules directly online!</p>
        </div>
        
        <div class="buttons">
            <a href="login.php" class="button">Staff Login</a>
            <a href="student-dashboard.php" class="button">Student Corner</a>
        </div>
    </div>

    <!-- Loading Spinner -->
    <div class="loading">
        <div class="spinner"></div>
    </div>

    <!-- Custom Scripts -->
    <script>
        // You can add any JavaScript for interactive features here
    </script>
</body>
</html>

