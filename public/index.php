<?php
declare(strict_types=1);

$projectRoot = dirname(__DIR__);
$dataDir = getenv('YTMP3_DATA_DIR') ?: $projectRoot . '/storage';
$jobsDir = $dataDir . '/jobs';

function sendJson(array $payload, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function writeJsonFile(string $path, array $data): bool {
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        return false;
    }
    $temporary = $path . '.' . bin2hex(random_bytes(5)) . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($temporary, $json, LOCK_EX) === false) {
        @unlink($temporary);
        return false;
    }
    @chmod($temporary, 0600);
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        return false;
    }
    return true;
}

function getYouTubeVideoId(string $url): ?string {
    if (strlen($url) > 2048) return null;
    $parts = parse_url(trim($url));
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) return null;
    if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) return null;

    $host = strtolower(rtrim($parts['host'], '.'));
    $allowedHosts = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'www.youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];
    if (!in_array($host, $allowedHosts, true)) return null;

    $path = trim((string)($parts['path'] ?? ''), '/');
    $query = [];
    parse_str((string)($parts['query'] ?? ''), $query);
    $id = null;

    if (in_array($host, ['youtu.be', 'www.youtu.be'], true)) {
        $segments = explode('/', $path);
        $id = $segments[0] ?? null;
    } elseif (isset($query['v'])) {
        $id = (string)$query['v'];
    } elseif (preg_match('~^(?:shorts|live|embed|v)/([A-Za-z0-9_-]{11})(?:/.*)?$~', $path, $matches)) {
        $id = $matches[1];
    }

    return is_string($id) && preg_match('/^[A-Za-z0-9_-]{11}$/', $id) ? $id : null;
}

if (isset($_GET['api'])) {
    $api = (string)$_GET['api'];
    if ($api === 'create') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendJson(['success' => false, 'error' => 'Use POST to create a job.'], 405);
        $raw = file_get_contents('php://input');
        $body = json_decode($raw ?: '', true);
        if (!is_array($body)) sendJson(['success' => false, 'error' => 'Invalid JSON request.'], 400);

        $url = trim((string)($body['url'] ?? ''));
        $format = strtolower(trim((string)($body['format'] ?? 'mp3')));
        if (!in_array($format, ['mp3', 'mp4'], true)) sendJson(['success' => false, 'error' => 'Choose MP3 or MP4.'], 422);
        $videoId = getYouTubeVideoId($url);
        if ($videoId === null) sendJson(['success' => false, 'error' => 'Enter a valid YouTube video link.'], 422);

        if (!is_dir($jobsDir) && !mkdir($jobsDir, 0700, true) && !is_dir($jobsDir)) {
            sendJson(['success' => false, 'error' => 'Job storage is not available.'], 500);
        }

        // Small per-IP throttle to discourage accidental repeated submissions.
        $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        $recentCount = 0;
        $cutoff = time() - 60;
        foreach (glob($jobsDir . '/*.json') ?: [] as $existingFile) {
            $existing = json_decode((string)@file_get_contents($existingFile), true);
            if (is_array($existing) && ($existing['ipHash'] ?? '') === $ipHash && (int)($existing['createdUnix'] ?? 0) >= $cutoff) {
                $recentCount++;
            }
        }
        if ($recentCount >= 8) sendJson(['success' => false, 'error' => 'Too many requests. Please wait a minute and try again.'], 429);

        $id = bin2hex(random_bytes(16));
        $now = gmdate('c');
        $job = [
            'id' => $id,
            'url' => 'https://www.youtube.com/watch?v=' . $videoId,
            'videoId' => $videoId,
            'format' => $format,
            'status' => 'queued',
            'title' => '',
            'downloadURL' => '',
            'error' => '',
            'createdAt' => $now,
            'updatedAt' => $now,
            'createdUnix' => time(),
            'ipHash' => $ipHash,
        ];
        if (!writeJsonFile($jobsDir . '/' . $id . '.json', $job)) {
            sendJson(['success' => false, 'error' => 'Could not queue the conversion. Check storage permissions.'], 500);
        }
        sendJson(['success' => true, 'job' => ['id' => $id, 'status' => 'queued', 'format' => $format]], 202);
    }

    if ($api === 'status') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendJson(['success' => false, 'error' => 'Use GET to check a job.'], 405);
        $id = (string)($_GET['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) sendJson(['success' => false, 'error' => 'Invalid job ID.'], 400);
        $file = $jobsDir . '/' . $id . '.json';
        if (!is_file($file)) sendJson(['success' => false, 'error' => 'Job not found or expired.'], 404);
        $job = json_decode((string)file_get_contents($file), true);
        if (!is_array($job)) sendJson(['success' => false, 'error' => 'Could not read job status.'], 500);
        $publicJob = [
            'id' => $job['id'],
            'status' => $job['status'],
            'format' => $job['format'],
        ];
        if (($job['status'] ?? '') === 'completed') {
            $publicJob['title'] = (string)($job['title'] ?? 'Your file is ready');
            $downloadURL = (string)($job['downloadURL'] ?? '');
            $parts = parse_url($downloadURL);
            if (is_array($parts) && in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
                $publicJob['downloadURL'] = $downloadURL;
            }
        } elseif (($job['status'] ?? '') === 'failed') {
            $publicJob['error'] = (string)($job['error'] ?? 'Conversion failed. Please try again.');
        }
        sendJson(['success' => true, 'job' => $publicJob]);
    }

    sendJson(['success' => false, 'error' => 'Unknown API endpoint.'], 404);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#ffffff">
  <meta name="description" content="A simple YouTube link converter for MP3 audio and MP4 video.">
  <title>YouTube to MP3 Converter</title>
  <style>
    :root{color-scheme:light;--text:#252525;--muted:#6f7378;--line:#d9dde2;--soft:#f6f7f8;--blue:#246fe5;--blue-hover:#195ecb;--success:#167647;--error:#b42318}
    *{box-sizing:border-box}
    html{scroll-behavior:smooth}
    body{margin:0;background:#fff;color:var(--text);font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:1.55;-webkit-font-smoothing:antialiased}
    a{color:var(--blue);text-decoration:none}
    a:hover{text-decoration:underline}
    .site-header{height:62px;border-bottom:1px solid #eceef0;display:flex;align-items:center}
    .header-inner{width:min(100% - 40px,1040px);margin:0 auto;display:flex;justify-content:space-between;align-items:center;gap:20px}
    .brand{font-size:22px;line-height:1;font-weight:700;letter-spacing:-1.1px;color:#252525;white-space:nowrap}
    .brand span{color:var(--blue)}
    .nav{display:flex;align-items:center;gap:25px;font-size:14px}
    .nav a{color:#4f5358}
    .nav a:hover{color:var(--blue);text-decoration:none}
    main{width:min(100% - 40px,940px);margin:0 auto}
    .converter-section{padding:64px 0 46px;text-align:center}
    .eyebrow{margin:0 0 13px;font-size:15px;color:#454a50}
    h1{margin:0 0 28px;font-size:32px;line-height:1.2;letter-spacing:-.8px;font-weight:700}
    .converter{max-width:780px;margin:0 auto}
    .input-row{display:flex;width:100%;gap:10px}
    .url-input{min-width:0;flex:1;height:52px;padding:0 16px;border:1px solid #cfd4da;border-radius:4px;background:#fff;font:inherit;color:var(--text);outline:none;box-shadow:0 1px 2px #00000008;transition:border-color .15s,box-shadow .15s}
    .url-input::placeholder{color:#979ca3}
    .url-input:focus{border-color:var(--blue);box-shadow:0 0 0 3px #246fe51c}
    .convert-btn{height:52px;min-width:124px;padding:0 24px;border:1px solid var(--blue);border-radius:4px;background:var(--blue);color:#fff;font:600 15px Arial,Helvetica,sans-serif;cursor:pointer;transition:background .15s,border-color .15s,transform .12s}
    .convert-btn:hover{background:var(--blue-hover);border-color:var(--blue-hover)}
    .convert-btn:active{transform:translateY(1px)}
    .convert-btn:disabled{opacity:.65;cursor:wait}
    .format-line{margin:14px 0 0;color:#686d73;font-size:14px}
    .format-toggle{font-size:14px;font-weight:600}
    .format-toggle:hover{text-decoration:underline}
    .notice{display:none;max-width:780px;margin:22px auto 0;padding:13px 15px;text-align:left;border:1px solid var(--line);border-radius:4px;background:#fafbfc;color:#454a50;font-size:14px}
    .notice.show{display:block;animation:fade-in .18s ease-out}
    .notice[data-type="error"]{border-color:#f0c8c5;background:#fff8f7;color:var(--error)}
    .notice[data-type="success"]{border-color:#b9dfc9;background:#f5fcf7;color:var(--success)}
    .notice-title{display:block;margin-bottom:3px;font-weight:700;color:inherit}
    .notice a{font-weight:700}
    .content{max-width:780px;margin:0 auto;padding:14px 0 30px}
    .content h1{margin:0 0 12px;font-size:24px;line-height:1.3;letter-spacing:-.45px}
    .content p{margin:0 0 20px;color:#4e5359}
    .content h3{margin:26px 0 10px;font-size:20px;line-height:1.35}
    .steps{margin:0 0 20px;padding-left:23px;color:#4e5359}
    .steps li{padding:3px 0 3px 2px}
    .thanks{margin-top:25px!important}
    footer{border-top:1px solid #eceef0;margin-top:10px}
    .footer-inner{width:min(100% - 40px,1040px);min-height:64px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:20px;color:#7b8086;font-size:13px}
    .footer-links{display:flex;gap:18px;flex-wrap:wrap}
    .footer-links a{color:#666c72}
    @keyframes fade-in{from{opacity:0;transform:translateY(3px)}to{opacity:1;transform:translateY(0)}}
    @media(max-width:640px){
      .site-header{height:auto;min-height:60px}
      .header-inner{width:calc(100% - 30px);padding:16px 0;align-items:flex-start;flex-direction:column;gap:13px}
      .nav{width:100%;gap:0;justify-content:space-between;font-size:13px}
      main{width:calc(100% - 30px)}
      .converter-section{padding:45px 0 35px}
      h1{font-size:27px;margin-bottom:23px}
      .input-row{flex-direction:column;gap:9px}
      .url-input,.convert-btn{width:100%;height:50px}
      .convert-btn{min-width:0}
      .content h1{font-size:22px}
      .footer-inner{width:calc(100% - 30px);padding:17px 0;align-items:flex-start;flex-direction:column;gap:8px}
      .footer-links{gap:14px}
    }
    @media(prefers-reduced-motion:reduce){html{scroll-behavior:auto}*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important}}
  </style>
</head>
<body id="top">
  <header class="site-header">
    <div class="header-inner">
      <a class="brand" href="#top" aria-label="YTMP3 home">ytmp3<span>.</span></a>
      <nav class="nav" aria-label="Main navigation">
        <a href="#top">Home</a>
        <a href="https://ytmp3.gl/faq/" target="_blank" rel="noopener noreferrer">FAQ</a>
        <a href="https://ytmp3.gl/add-on/" target="_blank" rel="noopener noreferrer">Add-On</a>
        <a href="https://ytmp3.gl/bookmarklet/" target="_blank" rel="noopener noreferrer">Bookmarklet</a>
      </nav>
    </div>
  </header>

  <main>
    <section class="converter-section" aria-labelledby="page-title">
      <p class="eyebrow">Paste your YouTube link</p>
      <form id="converter-form" class="converter" novalidate>
        <div class="input-row">
          <input id="youtube-url" class="url-input" type="url" inputmode="url" autocomplete="url" placeholder="youtube.com/watch?v=" aria-label="YouTube video URL" required maxlength="2048">
          <button class="convert-btn" id="convert-btn" type="submit">Convert</button>
        </div>
        <p class="format-line">Format: <strong id="format-label">MP3</strong> (<a class="format-toggle" id="format-toggle" href="#format">change to MP4</a>)</p>
      </form>
      <div class="notice" id="notice" role="status" aria-live="polite"></div>
    </section>

    <section class="content" id="faq">
      <h1 id="page-title">YouTube to MP3 Converter</h1>
      <p>YTMP3 is an online YouTube to MP3 converter that lets you convert YouTube videos to MP3 or MP4 files and download them. The service works in a modern web browser and does not require an account.</p>

      <h3>How to use YTMP3</h3>
      <ol class="steps">
        <li>Go to YouTube and open the video you want to process.</li>
        <li>Copy the video link and paste it into the converter above.</li>
        <li>Choose a format. MP3 is selected by default; use the format link to switch to MP4.</li>
        <li>Click <strong>Convert</strong> and wait for the job to finish. When a result is ready, use the download link shown on the page.</li>
      </ol>
      <p class="thanks">Thank you for using this converter. Please only process content you own or are authorized to download.</p>
    </section>
  </main>

  <footer>
    <div class="footer-inner">
      <span>© <span id="year"></span> ytmp3</span>
      <div class="footer-links">
        <a href="https://ytmp3.gl/contact/" target="_blank" rel="noopener noreferrer">Contact</a>
        <a href="https://ytmp3.gl/copyright-claims/" target="_blank" rel="noopener noreferrer">Copyright Claims</a>
        <a href="https://ytmp3.gl/privacy-policy/" target="_blank" rel="noopener noreferrer">Privacy Policy</a>
        <a href="https://ytmp3.gl/terms-of-use/" target="_blank" rel="noopener noreferrer">Terms of Use</a>
      </div>
    </div>
  </footer>

  <script>
    (() => {
      const form = document.getElementById('converter-form');
      const input = document.getElementById('youtube-url');
      const button = document.getElementById('convert-btn');
      const formatLabel = document.getElementById('format-label');
      const formatToggle = document.getElementById('format-toggle');
      const notice = document.getElementById('notice');
      let format = 'mp3';
      let pollAbort = false;

      document.getElementById('year').textContent = new Date().getFullYear();

      function setNotice(title, message, type = 'info', link = null) {
        notice.dataset.type = type;
        notice.replaceChildren();
        notice.classList.add('show');
        if (title) {
          const heading = document.createElement('span');
          heading.className = 'notice-title';
          heading.textContent = title;
          notice.appendChild(heading);
        }
        if (message) {
          const text = document.createElement('span');
          text.textContent = message;
          notice.appendChild(text);
        }
        if (link && /^https?:\/\//i.test(link.url)) {
          const line = document.createElement('div');
          line.style.marginTop = '10px';
          const anchor = document.createElement('a');
          anchor.href = link.url;
          anchor.target = '_blank';
          anchor.rel = 'noopener noreferrer';
          anchor.textContent = link.label || 'Download';
          line.appendChild(anchor);
          notice.appendChild(line);
        }
      }

      function clearNotice() {
        notice.classList.remove('show');
        notice.replaceChildren();
        delete notice.dataset.type;
      }

      formatToggle.addEventListener('click', (event) => {
        event.preventDefault();
        format = format === 'mp3' ? 'mp4' : 'mp3';
        formatLabel.textContent = format.toUpperCase();
        formatToggle.textContent = `change to ${format === 'mp3' ? 'MP4' : 'MP3'}`;
      });

      function isYouTubeUrl(value) {
        try {
          const url = new URL(value);
          const host = url.hostname.toLowerCase();
          const allowed = ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'music.youtube.com', 'youtu.be', 'www.youtu.be', 'youtube-nocookie.com', 'www.youtube-nocookie.com'];
          if (!['http:', 'https:'].includes(url.protocol) || !allowed.includes(host)) return false;
          const id = host.endsWith('youtu.be') ? url.pathname.split('/').filter(Boolean)[0] : (url.searchParams.get('v') || url.pathname.match(/^\/(?:shorts|live|embed|v)\/([\w-]{11})/)?.[1]);
          return /^[A-Za-z0-9_-]{11}$/.test(id || '');
        } catch { return false; }
      }

      async function requestJson(url, options = {}) {
        const response = await fetch(url, options);
        let data;
        try { data = await response.json(); } catch { throw new Error('The server returned an invalid response.'); }
        if (!response.ok || data.success === false) throw new Error(data.error || `Request failed (${response.status}).`);
        return data;
      }

      async function pollJob(id) {
        pollAbort = false;
        const started = Date.now();
        while (!pollAbort && Date.now() - started < 8 * 60 * 1000) {
          await new Promise(resolve => setTimeout(resolve, 1800));
          if (pollAbort) return;
          let data;
          try {
            data = await requestJson(`?api=status&id=${encodeURIComponent(id)}`, { cache: 'no-store' });
          } catch (error) {
            setNotice('Checking status…', 'A temporary connection issue occurred. Retrying automatically.');
            continue;
          }
          const job = data.job;
          if (job.status === 'queued') {
            setNotice('Queued', 'Your request is waiting for the worker.');
            continue;
          }
          if (job.status === 'processing') {
            setNotice('Converting…', 'Please keep this page open while the conversion is processed.');
            continue;
          }
          if (job.status === 'completed') {
            sessionStorage.removeItem('ytmp3-active-job');
            button.disabled = false;
            button.textContent = 'Convert';
            const safeUrl = typeof job.downloadURL === 'string' && /^https?:\/\//i.test(job.downloadURL) ? job.downloadURL : null;
            if (!safeUrl) {
              setNotice('Conversion finished', 'The service did not return a valid download link.', 'error');
              return;
            }
            setNotice('Your file is ready', job.title || `Your ${String(job.format || format).toUpperCase()} file has been prepared.`, 'success', { url: safeUrl, label: 'Download file' });
            return;
          }
          if (job.status === 'failed') {
            sessionStorage.removeItem('ytmp3-active-job');
            button.disabled = false;
            button.textContent = 'Convert';
            setNotice('Conversion failed', job.error || 'Please check the link and try again.', 'error');
            return;
          }
        }
        button.disabled = false;
        button.textContent = 'Convert';
        setNotice('Still processing', 'This page stopped checking after eight minutes. You can try again; the server may still be processing the job.', 'error');
      }

      form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (button.disabled) return;
        const url = input.value.trim();
        if (!isYouTubeUrl(url)) {
          setNotice('Check the link', 'Paste a valid YouTube video URL, including a standard watch link, youtu.be link, Short, or supported embed URL.', 'error');
          input.focus();
          return;
        }
        clearNotice();
        button.disabled = true;
        button.textContent = 'Please wait…';
        setNotice('Submitting…', 'Creating your conversion job.');
        try {
          const data = await requestJson('?api=create', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ url, format })
          });
          const id = data.job.id;
          sessionStorage.setItem('ytmp3-active-job', id);
          setNotice('Queued', 'Your conversion job has been created.');
          await pollJob(id);
        } catch (error) {
          button.disabled = false;
          button.textContent = 'Convert';
          setNotice('Unable to start conversion', error.message || 'Please try again.', 'error');
        }
      });

      const previousJob = sessionStorage.getItem('ytmp3-active-job');
      if (previousJob && /^[a-f0-9]{32}$/.test(previousJob)) {
        button.disabled = true;
        button.textContent = 'Please wait…';
        setNotice('Restoring job…', 'Checking the status of your previous conversion.');
        pollJob(previousJob).catch(() => {
          button.disabled = false;
          button.textContent = 'Convert';
        });
      }
    })();
  </script>
</body>
</html>
