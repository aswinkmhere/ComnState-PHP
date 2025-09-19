<!DOCTYPE html>
<html>
<head>
  <title>Comn State - Home</title>
  <link rel="stylesheet" href="css/bootstrap.min.css"/>
  <link rel="stylesheet" href="css/style.css"/>

  <style>
    body{
      background-image: url("images/graphicsBG.jpg"); /* Replace with your image path */
      background-size: cover; /* Ensures it covers the full screen */
      background-position: center; /* Centers the image */
      background-repeat: no-repeat; /* Prevents tiling */
      background-attachment: fixed; /* Keeps background fixed while scrolling */
    }

    .diagram {
      display: flex;
      align-items: center;
    }

    /* Left Circle */
    .circle {
      width: 180px;
      height: 180px;
      background: #D37616; /* Orange */
      border-radius: 50%;
      display: flex;
      justify-content: center;
      align-items: center;
      color: white;
      font-size: 24px;
      font-weight: bold;
      text-align: center;
      line-height: 1.2;
      padding: 10px;
      box-sizing: border-box;
      z-index:999;
    }

    #circle1{
      background: #00A2AF;
    }

    #circle2{
      background: #5515D5;
    }


    /* Right Bars */
    .bars {
         display: flex;
        flex-direction: column;
        margin-left: -75px;
    }

    .bar {
      background: #D37616;
      background: linear-gradient(90deg,rgba(211, 118, 22, 1) 0%, rgba(254, 153, 49, 1) 30%, rgba(254, 153, 49, 1) 100%);
      color: white;
      font-size: 22px;
      font-weight: bold;
      padding: 20px 30px;
      margin: 10px 0;
      border-radius: 0 50px 50px 0;
      min-width: 350px;
      text-align: center;
      cursor: pointer;
      transition: all ease-in 0.3s;
    }

    .bar1{
      background: linear-gradient(90deg,rgba(0, 162, 175, 1) 0%, rgba(128, 215, 222, 1) 30%, rgba(128, 215, 222, 1) 100%);
    }

    .bar2{
      background: linear-gradient(90deg,rgba(85, 21, 213, 1) 0%, rgba(123, 50, 254, 1) 30%, rgba(123, 50, 254, 1) 100%);
    }
    .bar:hover a{
      color: #fff;
      
    }
    .bar:hover{
      padding-left: 40px;
      background: linear-gradient(90deg,rgba(211, 118, 22, 1) 0%, rgba(254, 153, 49, 1) 35%, rgba(254, 153, 49, 1) 100%);
    }

    .bar1:hover{
      background: linear-gradient(90deg,rgba(0, 162, 175, 1) 0%, rgba(128, 215, 222, 1) 35%, rgba(128, 215, 222, 1) 100%);
    }
    .bar2:hover{
      background: linear-gradient(90deg,rgba(85, 21, 213, 1) 0%, rgba(123, 50, 254, 1) 35%, rgba(123, 50, 254, 1) 100%);
    }
    .bar a{
      text-decoration: none!important;
      color: #fff;
    }
    .index-right{
      padding: 70px 5px;
    }
  </style>
  
</head>
<body>
   <a href="#" class="ribbon">Dagger Website</a>
  <section>
    <div class="col-md-5 f-left">

    </div>

    <div class="col-md-7 f-left index-right">
      <div class="diagram">
        <div class="circle" id="circle1">
          Radio<br>Comn
        </div>
        <div class="bars">
          <div class="bar bar1">RR</div>
          <div class="bar bar1">BBR</div>
        </div>
      </div>

      <div class="space" style="height: 40px;"></div>

      <div class="diagram">
        <div class="circle">
          Line<br>Comn
        </div>
        <div class="bars">
          <div class="bar"><a href="comn_state.php">OFC Layout</a></div>
        </div>
      </div>

      <div class="space" style="height: 40px;"></div>

      <div class="diagram">
        <div class="circle" id="circle2">
          NFS<br>Eqpt
        </div>
        <div class="bars">
          <div class="bar bar2"><a href="nfs_eqpt.php">NFS Eqpt</a></div>
        </div>
      </div>
    </div>
  

  </section>

    
  <script src="js/jquery-3.7.1.min.js"></script>
  <script src="js/bootstrap.min.js"></script>
</body>
</html>
