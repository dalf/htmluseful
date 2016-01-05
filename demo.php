<?php
include 'htmluseful.php';
if (isset($_GET['url'])) {
  $useful = htmluseful($_GET['url']);
}
?><html>
<head>
  <meta charset="UTF-8" >
  <title>htmluseful demo</title>
</head>
  <form action="demo.php">
    <input type="text" name="url" length="200" >
    <input type="submit" value="Submit" >
  </form>
<?php 
  if (isset($useful)) { 
    echo "<hr>\n";
    echo "<h1>" . $useful['title'] . "</h1>\n";
    $content_exists = array_key_exists('content', $useful);
    if (!$content_exists && array_key_exists('image', $useful)) {
      $buffer .= '<p><img src="'.$useful['image'].'" width="600px" style="width: 97%; max-width: 600px" height="auto"/></p>\n';
    }
    if (!$content_exists && array_key_exists('video', $maps)) {
      $buffer .= '<p><a href="' . $useful['video'] . '>' . $useful['video'] . '</p>\n';
    }
    if ($content_exists) {
      echo $useful['content'];
    } else if (array_key_exists('description', $useful)) {
      echo '<p>'.$useful['description'].'</p>\n';
    }
  }
?>
</html>
