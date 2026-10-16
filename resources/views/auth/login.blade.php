<x-guest-layout>
<style>
:root{--login-accent:#06ad73;--login-ink:#0f172a;--login-muted:#64748b;--login-surface:#fff;--login-line:#d9e1ea}
html[data-theme="dark"]{--login-ink:#f8fafc;--login-muted:#94a3b8;--login-surface:#07111f;--login-line:rgba(148,163,184,.22)}
body.login-page{margin:0;background:#f2f5f9;min-height:100vh;min-height:100dvh;overflow-x:hidden}
html[data-theme="dark"] body.login-page{background:#050d18}
.login-theme{position:fixed;top:18px;right:18px;z-index:10;width:42px;height:42px;border-radius:12px;border:1px solid var(--login-line);background:var(--login-surface);color:var(--login-ink);display:grid;place-items:center}
.login-shell{width:min(1080px,calc(100% - 32px));min-height:620px;margin:22px auto;display:grid;grid-template-columns:1.05fr .95fr;border:1px solid var(--login-line);border-radius:26px;overflow:hidden;background:var(--login-surface);box-shadow:0 28px 75px rgba(15,23,42,.16)}
.login-visual{padding:42px;display:flex;flex-direction:column;justify-content:center;gap:18px;background:linear-gradient(160deg,#eafbf4,#fff);border-right:1px solid var(--login-line)}
html[data-theme="dark"] .login-visual{background:linear-gradient(160deg,#0b2a22,#081321)}
.login-slogan{color:var(--login-ink);font-size:clamp(1.9rem,3vw,2.8rem);line-height:1.06;letter-spacing:-.045em;font-weight:900;max-width:540px;margin:0}
.login-media{display:grid;place-items:center;min-height:360px}.login-media img{width:100%;max-width:520px;max-height:390px;object-fit:contain;border-radius:20px}
.login-panel{padding:50px 46px;display:flex;flex-direction:column;justify-content:center}.login-brand{display:flex;align-items:center;gap:14px;margin-bottom:26px}.login-logo{width:78px;height:78px;border-radius:18px;display:grid;place-items:center;overflow:hidden;background:#eafbf4;border:1px solid #d0f4e5}.login-logo img{width:100%;height:100%;object-fit:contain;padding:10px}.login-brand strong{display:block;color:var(--login-ink);font-size:1.05rem}.login-brand small{display:block;color:var(--login-muted);margin-top:3px}
.login-head h1{font-size:2.7rem;font-weight:900;line-height:1;letter-spacing:-.055em;margin:0 0 24px;color:var(--login-ink)}
.login-field{display:flex;align-items:center;min-height:54px;border-bottom:2px solid var(--login-line);margin-bottom:14px}.login-field:focus-within{border-bottom-color:var(--login-accent)}.login-field span{width:38px;color:var(--login-accent);font-weight:800}.login-field input{flex:1;min-width:0;border:0;outline:0;background:transparent;color:var(--login-ink);padding:12px 0;font-size:1rem}.login-field input::placeholder{color:var(--login-muted)}
.login-row{display:flex;align-items:center;justify-content:space-between;gap:12px;margin:12px 0 18px;color:var(--login-muted);font-size:.86rem}.login-row a{color:var(--login-accent);font-weight:700;text-decoration:none}.login-submit{width:100%;min-height:56px;border:0;border-radius:999px;background:var(--login-accent);color:#fff;font-weight:900;box-shadow:0 14px 30px rgba(6,173,115,.24)}
.login-alert{border:0;border-radius:13px;background:#fff0f0;color:#a61b1b;padding:11px 13px;margin-bottom:18px;font-size:.86rem}.login-powered{text-align:center;margin-top:20px;color:var(--login-muted);font-size:.76rem}.login-powered strong{color:var(--login-ink)}
@media(max-width:860px){.login-shell{grid-template-columns:1fr;max-width:560px;min-height:0;margin-top:72px}.login-visual{display:none}.login-panel{padding:42px 30px}}
@media(max-width:520px){.login-shell{width:calc(100% - 16px);border-radius:20px}.login-theme{top:12px;right:12px}.login-panel{padding:30px 20px}.login-head h1{font-size:2.3rem}}
</style>
<script>
(function(){
 document.body.classList.add('login-page');
 var dark=localStorage.getItem('isp-theme')==='dark';
 document.documentElement.setAttribute('data-theme',dark?'dark':'light');
})();
</script>
<button class="login-theme" id="loginThemeToggle" type="button" aria-label="Toggle dark mode">◐</button>
<main class="login-shell">
<section class="login-visual">
<h1 class="login-slogan">Powering Smarter ISP Management with X-Link.</h1>
<div class="login-media">
<img src="{{ asset('images/front_logo_300_500.png') }}" alt="X-Link Limited">
</div>
</section>
<section class="login-panel">
<div class="login-brand">
<div class="login-logo">
@if(siteUrlSettings('site_logo'))<img src="{{ site_image(siteUrlSettings('site_logo')) }}" alt="{{ siteUrlSettings('site_name') }}">@elseif(siteUrlSettings('site_icon'))<img src="{{ site_image(siteUrlSettings('site_icon')) }}" alt="{{ siteUrlSettings('site_name') }}">@else<span style="font-size:1.7rem;font-weight:900;color:var(--login-accent)">XL</span>@endif
</div>
<div><strong>{{ siteUrlSettings('site_name') ?? 'X-Link Limited' }}</strong><small>Admin Panel</small></div>
</div>
<div class="login-head"><h1>Welcome Back</h1><p style="color:var(--login-muted);margin:-14px 0 24px;font-size:.95rem">Sign in to your X-Link ISP Management Panel</p></div>
@if(session('status'))<div class="login-alert" style="background:#ecfdf5;color:#047857">{{ session('status') }}</div>@endif
<x-validation-errors class="login-alert" />
<form class="login-form" method="POST" action="{{ route('login') }}">
@csrf
<div class="login-field"><span>✉</span><input id="email" type="text" name="email" value="{{ old('email') }}" required autocomplete="username" placeholder="Email address or username"></div>
<div class="login-field"><span>🔑</span><input id="password" type="password" name="password" placeholder="Password" required autocomplete="current-password"></div>
<div class="login-row">
<label style="display:flex;align-items:center;gap:8px;margin:0"><input type="checkbox" id="remember_me" name="remember"> {{ __('Remember me') }}</label>
@if(Route::has('password.request'))<a wire:navigate.hover href="{{ route('password.request') }}">{{ __('Forgot your password?') }}</a>@endif
</div>
<button type="submit" class="login-submit">SIGN IN</button>
@if(Route::has('register'))<div class="login-powered">Don't have an account? <a href="{{ route('register') }}">Sign up</a></div>@endif
</form>
<div class="login-powered">{{ __('Secure access') }} · <strong>{{ siteUrlSettings('site_name') ?? 'X-Link Limited' }}</strong></div>
</section>
</main>
<script>
document.getElementById('loginThemeToggle')?.addEventListener('click',function(){
 const dark=document.documentElement.getAttribute('data-theme')==='dark';
 document.documentElement.setAttribute('data-theme',dark?'light':'dark');
 localStorage.setItem('isp-theme',dark?'light':'dark');
});
</script>
</x-guest-layout>
