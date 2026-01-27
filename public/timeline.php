<?php
$dbh = new PDO('mysql:host=mysql;dbname=example_db', 'root', '');
session_start();
if (empty($_SESSION['login_user_id'])) {
  header("Location: ./login.php");
  return;
}

// 投稿処理
if (isset($_POST['body']) && !empty($_SESSION['login_user_id'])) {
  $image_filenames = [null, null, null, null];

  for ($i = 0; $i < 4; $i++) {
    $key = 'image_base64_' . $i;
    if (!empty($_POST[$key])) {
      $base64 = preg_replace('/^data:.+base64,/', '', $_POST[$key]);
      $binary = base64_decode($base64);
      $name = time() . bin2hex(random_bytes(10)) . "_{$i}.png";
      file_put_contents('/var/www/upload/image/' . $name, $binary);
      $image_filenames[$i] = $name;
    }
  }

  $insert = $dbh->prepare("INSERT INTO bbs_entries (user_id, body, image_filename, image_filename2, image_filename3, image_filename4) VALUES (?, ?, ?, ?, ?, ?)");
  $insert->execute([$_SESSION['login_user_id'], $_POST['body'], $image_filenames[0], $image_filenames[1], $image_filenames[2], $image_filenames[3]]);
  header("Location: ./timeline.php");
  return;
}
?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title>タイムライン</title></head>
<body>
  <form method="POST" action="./timeline.php">
    <textarea name="body" required></textarea><br>
    <input type="file" id="imageInput" multiple accept="image/*"><br>
    <div id="hiddenInputs"></div>
    <button type="submit">投稿</button>
  </form>
  <hr>
  <div id="entriesRenderArea"></div>
  <div id="loadMarker" style="text-align:center;">読み込み中...</div>

  <template id="entryTemplate">
    <div style="border-bottom:1px solid #ccc; padding:10px;">
      <strong class="user-name"></strong>
      <p class="entry-body"></p>
      <div class="entry-images"></div> </div>
  </template>

<script>
let page = 0;
const imageInput = document.getElementById('imageInput');

// 画像4枚のリサイズとセット
imageInput.onchange = async (e) => {
  const files = Array.from(e.target.files).slice(0, 4);
  const container = document.getElementById('hiddenInputs');
  container.innerHTML = '';
  for (let i = 0; i < files.length; i++) {
    const base64 = await resize(files[i]);
    container.innerHTML += `<input type="hidden" name="image_base64_${i}" value="${base64}">`;
  }
};

async function resize(file) {
  return new Promise(res => {
    const r = new FileReader();
    r.onload = e => {
      const img = new Image();
      img.onload = () => {
        const c = document.createElement('canvas');
        c.width = 500; c.height = 500 * (img.height / img.width);
        c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
        res(c.toDataURL('image/png'));
      };
      img.src = e.target.result;
    };
    r.readAsDataURL(file);
  });
}

// 無限スクロールと4枚表示
const fetchTimeline = () => {
  fetch(`./timeline_json.php?page=${page}`).then(r => r.json()).then(data => {
    if (data.entries.length === 0) return;
    data.entries.forEach(entry => {
      const clone = document.getElementById('entryTemplate').content.cloneNode(true);
      clone.querySelector('.user-name').innerText = entry.user_name;
      clone.querySelector('.entry-body').innerText = entry.body;
      
      
      const imgBox = clone.querySelector('.entry-images');
      entry.image_file_url.forEach(url => {
        if (!url) return;
        const img = document.createElement('img');
        img.src = url; img.style.width = '100px';
        imgBox.appendChild(img);
      });
      // ------------------------------
      document.getElementById('entriesRenderArea').appendChild(clone);
    });
    page++;
  });
};

new IntersectionObserver(e => { if(e[0].isIntersecting) fetchTimeline(); }).observe(document.getElementById('loadMarker'));
</script>
</body>
</html>
