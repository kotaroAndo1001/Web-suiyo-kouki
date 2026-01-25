<?php
$dbh = new PDO('mysql:host=mysql;dbname=example_db', 'root', '');

session_start();
if (empty($_SESSION['login_user_id'])) { // 非ログインの場合利用不可
  header("HTTP/1.1 302 Found");
  header("Location: /login.php");
  return;
}

// 現在のログイン情報を取得する
$user_select_sth = $dbh->prepare("SELECT * from users WHERE id = :id");
$user_select_sth->execute([':id' => $_SESSION['login_user_id']]);
$user = $user_select_sth->fetch();

// 投稿処理
if (isset($_POST['body']) && !empty($_SESSION['login_user_id'])) {

  $image_filename = null;
  if (!empty($_POST['image_base64'])) {
    // 先頭の data:~base64, のところは削る
    $base64 = preg_replace('/^data:.+base64,/', '', $_POST['image_base64']);

    // base64からバイナリにデコードする
    $image_binary = base64_decode($base64);

    // 新しいファイル名を決めてバイナリを出力する
    $image_filename = strval(time()) . bin2hex(random_bytes(25)) . '.png';
    $filepath =  '/var/www/upload/image/' . $image_filename;
    file_put_contents($filepath, $image_binary);
  }

  // insertする
  $insert_sth = $dbh->prepare("INSERT INTO bbs_entries (user_id, body, image_filename) VALUES (:user_id, :body, :image_filename)");
  $insert_sth->execute([
    ':user_id' => $_SESSION['login_user_id'], // ログインしている会員情報の主キー
    ':body' => $_POST['body'], // フォームから送られてきた投稿本文
    ':image_filename' => $image_filename, // 保存した画像の名前 (nullの場合もある)
  ]);

  // 処理が終わったらリダイレクトする
  header("HTTP/1.1 303 See Other");
  header("Location: ./timeline.php");
  return;
}
?>

<div>
  現在 <?= htmlspecialchars($user['name']) ?> (ID: <?= $user['id'] ?>) さんでログイン中
</div>
<div style="margin-bottom: 1em;">
  <a href="/setting/index.php">設定画面</a> / <a href="/users.php">会員一覧画面</a>
</div>

<form method="POST" action="./timeline.php">
  <textarea name="body" required style="width:100%; height:5em;"></textarea>
  <div style="margin: 1em 0;">
    <input type="file" accept="image/*" id="imageInput">
  </div>
  <input id="imageBase64Input" type="hidden" name="image_base64">
  <canvas id="imageCanvas" style="display: none;"></canvas>
  <button type="submit">送信</button>
</form>
<hr>

<div id="entriesRenderArea"></div>
<div id="loadMarker" style="text-align:center; padding: 20px;">読み込み中...</div>

<dl id="entryTemplate" style="display: none; margin-bottom: 1em; padding-bottom: 1em; border-bottom: 1px solid #ccc;">
  <dt>番号</dt>
  <dd data-role="entryIdArea"></dd>
  <dt>投稿者</dt>
  <dd>
    <a href="" data-role="entryUserAnchor">
      <img data-role="entryUserIconImage" style="height: 2em; width: 2em; border-radius: 50%; object-fit: cover;">
      <span data-role="entryUserNameArea"></span>
    </a>
  </dd>
  <dt>日時</dt>
  <dd data-role="entryCreatedAtArea"></dd>
  <dt>内容</dt>
  <dd data-role="entryBodyArea"></dd>
</dl>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const entryTemplate = document.getElementById('entryTemplate');
  const entriesRenderArea = document.getElementById('entriesRenderArea');
  let page = 0; // 読み込みページ管理
  let isFull = false; // 全件読み込みフラグ

  // タイムライン取得関数
  const fetchTimeline = () => {
    if (isFull) return;
    const request = new XMLHttpRequest();
    request.onload = (event) => {
      const response = event.target.response;
      if (!response.entries || response.entries.length === 0) {
        isFull = true;
        document.getElementById('loadMarker').innerText = "全ての投稿を表示しました";
        return;
      }
      response.entries.forEach((entry) => {
        const entryCopied = entryTemplate.cloneNode(true);
        entryCopied.style.display = 'block';
        entryCopied.querySelector('[data-role="entryIdArea"]').innerText = entry.id;
        entryCopied.querySelector('[data-role="entryUserNameArea"]').innerText = entry.user_name;
        entryCopied.querySelector('[data-role="entryUserAnchor"]').href = entry.user_profile_url;
        entryCopied.querySelector('[data-role="entryCreatedAtArea"]').innerText = entry.created_at;
        entryCopied.querySelector('[data-role="entryBodyArea"]').innerHTML = entry.body;

        if (entry.user_icon_file_url) {
          entryCopied.querySelector('[data-role="entryUserIconImage"]').src = entry.user_icon_file_url;
        }
        if (entry.image_file_url) {
          const img = new Image();
          img.src = entry.image_file_url;
          img.style.cssText = "display:block; margin-top:1em; max-height:300px; max-width:300px;";
          entryCopied.querySelector('[data-role="entryBodyArea"]').appendChild(img);
        }
        entriesRenderArea.appendChild(entryCopied);
      });
      page++;
    };
    request.open('GET', `/timeline_json.php?page=${page}`, true);
    request.responseType = 'json';
    request.send();
  };

  // スクロール監視設定
  const observer = new IntersectionObserver((entries) => {
    if (entries[0].isIntersecting) fetchTimeline();
  });
  observer.observe(document.getElementById('loadMarker'));

  // 画像縮小処理 (Canvas API使用)
  const imageInput = document.getElementById("imageInput");
  imageInput.addEventListener("change", () => {
    if (imageInput.files.length < 1) return;
    const reader = new FileReader();
    reader.onload = () => {
      const img = new Image();
      img.onload = () => {
        const canvas = document.getElementById("imageCanvas");
        const maxLength = 1000;
        let w = img.naturalWidth, h = img.naturalHeight;
        if (w > maxLength || h > maxLength) {
          if (w > h) { h = maxLength * h / w; w = maxLength; }
          else { w = maxLength * w / h; h = maxLength; }
        }
        canvas.width = w; canvas.height = h;
        canvas.getContext("2d").drawImage(img, 0, 0, w, h);
        document.getElementById("imageBase64Input").value = canvas.toDataURL();
      };
      img.src = reader.result;
    };
    reader.readAsDataURL(imageInput.files[0]);
  });
});
</script>
