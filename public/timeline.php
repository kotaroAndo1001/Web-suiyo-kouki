<?php
$dbh = new PDO('mysql:host=mysql;dbname=example_db', 'root', '');

session_start();
if (empty($_SESSION['login_user_id'])) { // 非ログインの場合利用不可
  header("HTTP/1.1 302 Found");
  header("Location: ./login.php");
  return;
}

// 現在のログイン情報を取得する
$user_select_sth = $dbh->prepare("SELECT * from users WHERE id = :id");
$user_select_sth->execute([':id' => $_SESSION['login_user_id']]);
$user = $user_select_sth->fetch();

// 投稿処理
if (isset($_POST['body']) && !empty($_SESSION['login_user_id'])) {
  $image_filenames = [null, null, null, null];

  for ($i = 0; $i < 4; $i++) {
    $key = 'image_base64_' . $i;
    if (!empty($_POST[$key])) {
      // 先頭の data:~base64, のところは削る
      $base64 = preg_replace('/^data:.+base64,/', '', $_POST[$key]);
      // base64からバイナリにデコードする
      $image_binary = base64_decode($base64);
      
      // 新しいファイル名を決めてバイナリを出力する
      $filename = strval(time()) . bin2hex(random_bytes(20)) . "_{$i}.png";
      $filepath = '/var/www/upload/image/' . $filename;
      file_put_contents($filepath, $image_binary);
      
      $image_filenames[$i] = $filename;
    }
  }

  // insertする
  $insert_sth = $dbh->prepare("INSERT INTO bbs_entries (user_id, body, image_filename, image_filename2, image_filename3, image_filename4) VALUES (:user_id, :body, :img1, :img2, :img3, :img4)");
  $insert_sth->execute([
    ':user_id' => $_SESSION['login_user_id'], // ログインしている会員情報の主キー
    ':body' => $_POST['body'], // フォームから送られてきた投稿本文
    ':img1' => $image_filenames[0], // 保存した画像の名前(nullの場合もある)
    ':img2' => $image_filenames[1],
    ':img3' => $image_filenames[2],
    ':img4' => $image_filenames[3],
  ]);

  // 処理が終わったらリダイレクトする
  header("HTTP/1.1 303 See Other");
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
      <div class="entry-images"></div>
    </div>
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
      
      // 4枚の画像をループ表示
      const imgBox = clone.querySelector('.entry-images');
      entry.image_file_url.forEach(url => {
        if (!url) return;
        const img = document.createElement('img');
        img.src = url; img.style.width = '100px';
        imgBox.appendChild(img);
      });
      
      document.getElementById('entriesRenderArea').appendChild(clone);
    });
    page++;
  });
};

new IntersectionObserver(e => { if(e[0].isIntersecting) fetchTimeline(); }).observe(document.getElementById('loadMarker'));
</script>
</body>
</html>
