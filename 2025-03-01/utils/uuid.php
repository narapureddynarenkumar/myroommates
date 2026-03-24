<?php
function uuid() {
  return bin2hex(random_bytes(16));
}
?>