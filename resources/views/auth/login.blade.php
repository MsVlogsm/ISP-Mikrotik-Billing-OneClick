<x-guest-layout>
<style>
    html { font-size: 16px !important; min-height: 100%; }
    body.xlink-login-page {
        margin: 0 !important;
        min-height: 100vh;
        min-height: 100dvh;
        overflow: hidden;
        background: #f4f4f4 !important;
        color: #172b3d;
        font-family: Arial, Helvetica, sans-serif !important;
        line-height: 1.45;
    }
    body.xlink-login-page > div:first-child {
        width: 100%;
        min-height: 100vh;
        min-height: 100dvh;
    }
    body.xlink-login-page > section { display: none !important; }
    .xlink-login-screen {
        min-height: 100vh;
        min-height: 100dvh;
        width: 100%;
        display: grid;
        grid-template-columns: 33.1% 66.9%;
        background: #f4f4f4;
    }
    .xlink-login-brand {
        position: relative;
        display: flex;
        justify-content: center;
        align-items: center;
        min-width: 0;
        padding: 30px 20px 58px;
        background: #21415b;
        color: #fff;
    }
    .xlink-login-brand-inner {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        gap: 16px;
        width: 100%;
        margin-top: -2px;
        text-align: center;
    }
    .xlink-login-logo-circle {
        width: 205px;
        height: 205px;
        display: flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 auto;
        border-radius: 50%;
        background: #f6f6f6;
    }
    .xlink-login-logo-frame {
        width: 90px;
        height: 90px;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        background: #fff;
    }
    .xlink-login-logo-frame img {
        display: block;
        max-width: 100%;
        max-height: 100%;
        width: 100%;
        height: 100%;
        padding: 12px;
        object-fit: contain;
    }
    .xlink-login-company {
        margin: 0;
        color: #fff;
        font-size: 31px;
        line-height: 1.2;
        font-weight: 750;
        letter-spacing: .1px;
    }
    .xlink-login-version {
        position: absolute;
        left: 16px;
        bottom: 15px;
        color: #fff;
        font-size: 14px;
        font-weight: 700;
        line-height: 1;
    }
    .xlink-login-main {
        position: relative;
        display: flex;
        align-items: center;
        min-width: 0;
        background: #f4f4f4;
        padding: 60px 28px 30px clamp(36px, 5.2vw, 95px);
    }
    .xlink-client-registration {
        position: absolute;
        top: 20px;
        right: 35px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 36px;
        padding: 7px 14px;
        border: 1px solid #24435d;
        border-radius: 4px;
        background: transparent;
        color: #24435d;
        font-size: 14px;
        line-height: 20px;
        text-decoration: none;
        white-space: nowrap;
    }
    .xlink-client-registration:hover,
    .xlink-client-registration:focus {
        background: #21415b;
        color: #fff;
        text-decoration: none;
    }
    .xlink-login-content {
        width: 100%;
        max-width: 380px;
        margin-top: -3px;
    }
    .xlink-login-title {
        margin: 0 0 25px;
        color: #111f2d;
        font-size: 28px;
        line-height: 1.25;
        font-weight: 700;
        letter-spacing: 0;
    }
    .xlink-login-field {
        display: grid;
        grid-template-columns: 36px minmax(0, 1fr);
        width: 100%;
        min-height: 37px;
        margin: 0 0 14px;
        overflow: hidden;
        border: 1px solid #cbd3dc;
        border-radius: 4px;
        background: #fff;
        box-sizing: border-box;
    }
    .xlink-login-field:focus-within {
        border-color: #7891a7;
        box-shadow: 0 0 0 2px rgba(33, 65, 91, .08);
    }
    .xlink-login-field-icon {
        display: flex;
        align-items: center;
        justify-content: center;
        min-width: 36px;
        background: #21415b;
        color: #fff;
    }
    .xlink-login-field-icon svg { width: 16px; height: 16px; display: block; }
    .xlink-login-field input {
        display: block;
        width: 100%;
        min-width: 0;
        height: 35px;
        padding: 7px 12px;
        border: 0 !important;
        outline: 0 !important;
        border-radius: 0;
        box-shadow: none !important;
        background: #fff !important;
        color: #26384a !important;
        font-family: Arial, Helvetica, sans-serif !important;
        font-size: 14px;
        line-height: 20px;
        box-sizing: border-box;
    }
    .xlink-login-field input::placeholder { color: #66788a; opacity: 1; }
    .xlink-login-options {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        column-gap: 16px;
        row-gap: 8px;
        margin: 16px 0 15px;
        color: #28445e;
        font-size: 14px;
    }
    .xlink-login-remember {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        margin: 0;
        cursor: pointer;
        white-space: nowrap;
        font-weight: 400;
    }
    .xlink-login-remember input {
        width: 19px;
        height: 19px;
        margin: 0;
        accent-color: #21415b;
    }
    .xlink-login-forgot {
        color: #0878ef;
        font-size: 14px;
        text-decoration: none;
        white-space: nowrap;
    }
    .xlink-login-forgot:hover { color: #0759b0; text-decoration: underline; }
    .xlink-login-submit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 62px;
        min-height: 36px;
        padding: 7px 13px;
        border: 1px solid #21415b;
        border-radius: 4px;
        background: #21415b;
        color: #fff;
        font-size: 14px;
        line-height: 20px;
        font-weight: 400;
        cursor: pointer;
    }
    .xlink-login-submit:hover,
    .xlink-login-submit:focus { background: #17334a; border-color: #17334a; color: #fff; }
    .xlink-login-errors,
    .xlink-login-status {
        margin: 0 0 14px;
        padding: 10px 12px;
        border: 1px solid #f1b7b7;
        border-radius: 4px;
        background: #fff1f1;
        color: #912525;
        font-size: 13px;
    }
    .xlink-login-status { border-color: #b7e3cb; background: #effbf4; color: #17663a; }
    .xlink-login-errors ul { margin: 0; padding-left: 18px; }
    @media (max-width: 900px) {
        .xlink-login-screen { grid-template-columns: 32% 68%; }
        .xlink-login-logo-circle { width: 155px; height: 155px; }
        .xlink-login-logo-frame { width: 76px; height: 76px; }
        .xlink-login-company { font-size: 25px; }
        .xlink-login-main { padding-left: 34px; padding-right: 24px; }
        .xlink-client-registration { right: 20px; }
    }
    @media (max-width: 640px) {
        body.xlink-login-page { overflow-y: auto; }
        .xlink-login-screen {
            min-height: 100vh;
            min-height: 100dvh;
            grid-template-columns: minmax(0, 1fr);
            grid-template-rows: 190px minmax(420px, 1fr);
        }
        .xlink-login-brand { padding: 18px 16px 28px; }
        .xlink-login-brand-inner { flex-direction: row; gap: 14px; margin-top: 0; }
        .xlink-login-logo-circle { width: 104px; height: 104px; }
        .xlink-login-logo-frame { width: 65px; height: 65px; }
        .xlink-login-company { font-size: 22px; text-align: left; }
        .xlink-login-version { left: 12px; bottom: 8px; font-size: 11px; }
        .xlink-login-main { align-items: flex-start; padding: 68px 22px 32px; }
        .xlink-client-registration { top: 13px; right: 16px; min-height: 32px; padding: 5px 10px; font-size: 12px; }
        .xlink-login-content { max-width: 420px; margin: 20px auto 0; }
        .xlink-login-title { font-size: 25px; margin-bottom: 22px; }
    }
    @media (max-width: 360px) {
        .xlink-login-screen { grid-template-rows: 165px minmax(400px, 1fr); }
        .xlink-login-logo-circle { width: 86px; height: 86px; }
        .xlink-login-logo-frame { width: 56px; height: 56px; }
        .xlink-login-company { font-size: 19px; }
        .xlink-login-main { padding-right: 16px; padding-left: 16px; }
    }
/* X-LINK ANIMATED LOGIN — presentation layer */
    body.xlink-login-page {
        background: #071426 !important;
        color: #edf7ff;
    }
    body.xlink-login-page > div:first-child,
    .xlink-login-screen {
        background: transparent;
    }
    .xlink-login-screen {
        position: relative;
        isolation: isolate;
        overflow: hidden;
        background:
            radial-gradient(ellipse at 78% 18%, rgba(32, 190, 207, .20), transparent 34%),
            radial-gradient(ellipse at 24% 78%, rgba(105, 82, 234, .23), transparent 38%),
            linear-gradient(128deg, #071426 0%, #102945 48%, #10213c 100%);
    }
    .xlink-login-screen::before,
    .xlink-login-screen::after {
        content: "";
        position: absolute;
        inset: -18%;
        z-index: -1;
        pointer-events: none;
    }
    .xlink-login-screen::before {
        background:
            radial-gradient(circle at 18% 25%, rgba(44, 201, 221, .22) 0, transparent 22%),
            radial-gradient(circle at 76% 35%, rgba(130, 105, 255, .24) 0, transparent 25%),
            radial-gradient(circle at 58% 84%, rgba(27, 174, 166, .17) 0, transparent 22%);
        filter: blur(18px);
        animation: xlink-aurora-drift 24s ease-in-out infinite alternate;
        transform-origin: center;
    }
    .xlink-login-screen::after {
        inset: 0;
        opacity: .13;
        background-image:
            linear-gradient(rgba(174, 218, 245, .22) 1px, transparent 1px),
            linear-gradient(90deg, rgba(174, 218, 245, .22) 1px, transparent 1px);
        background-size: 58px 58px;
        mask-image: radial-gradient(ellipse at center, #000 12%, transparent 78%);
        animation: xlink-grid-drift 42s linear infinite;
    }
    @keyframes xlink-aurora-drift {
        0% { transform: translate3d(-2%, -1%, 0) scale(1); }
        50% { transform: translate3d(2%, 2%, 0) scale(1.08); }
        100% { transform: translate3d(-1%, 3%, 0) scale(1.03); }
    }
    @keyframes xlink-grid-drift {
        from { background-position: 0 0, 0 0; }
        to { background-position: 116px 58px, 58px 116px; }
    }
    .xlink-login-brand {
        z-index: 1;
        background: rgba(5, 17, 33, .38);
        border-right: 1px solid rgba(191, 226, 255, .15);
        color: #f4faff;
        -webkit-backdrop-filter: blur(12px);
        backdrop-filter: blur(12px);
    }
    .xlink-login-logo-circle {
        width: 190px;
        height: 190px;
        background: rgba(255, 255, 255, .94);
        border: 1px solid rgba(255, 255, 255, .52);
        box-shadow: 0 18px 65px rgba(0, 0, 0, .22), 0 0 55px rgba(74, 206, 226, .16);
    }
    .xlink-login-logo-frame {
        width: 112px;
        height: 112px;
        border-radius: 22px;
        background: #fff;
    }
    .xlink-login-company {
        color: #fff;
        font-size: clamp(25px, 2.2vw, 34px);
        font-weight: 800;
        letter-spacing: .2px;
        text-shadow: 0 4px 24px rgba(0, 0, 0, .28);
    }
    .xlink-login-version {
        left: 22px;
        bottom: 20px;
        padding: 8px 11px;
        border: 1px solid rgba(202, 231, 255, .20);
        border-radius: 999px;
        background: rgba(255, 255, 255, .08);
        color: rgba(239, 248, 255, .82);
        font-size: 11px;
        letter-spacing: .08em;
        -webkit-backdrop-filter: blur(12px);
        backdrop-filter: blur(12px);
    }
    .xlink-login-main {
        z-index: 1;
        background: transparent;
        padding-top: 76px;
        padding-bottom: 44px;
    }
    .xlink-client-registration {
        top: 24px;
        right: 34px;
        min-height: 40px;
        padding: 9px 16px;
        border: 1px solid rgba(209, 237, 255, .38);
        border-radius: 999px;
        background: rgba(255, 255, 255, .08);
        color: #eaf7ff;
        font-weight: 600;
        -webkit-backdrop-filter: blur(12px);
        backdrop-filter: blur(12px);
        transition: background .2s ease, border-color .2s ease, transform .2s ease;
    }
    .xlink-client-registration:hover,
    .xlink-client-registration:focus {
        border-color: rgba(255, 255, 255, .7);
        background: rgba(255, 255, 255, .16);
        color: #fff;
        transform: translateY(-1px);
    }
    .xlink-login-content {
        position: relative;
        max-width: 430px;
        margin-top: 0;
        padding: 38px 36px 34px;
        border: 1px solid rgba(221, 239, 255, .22);
        border-radius: 26px;
        background: linear-gradient(145deg, rgba(255, 255, 255, .15), rgba(255, 255, 255, .075));
        box-shadow: 0 28px 90px rgba(0, 0, 0, .32), inset 0 1px 0 rgba(255, 255, 255, .12);
        -webkit-backdrop-filter: blur(24px);
        backdrop-filter: blur(24px);
        animation: xlink-card-enter .65s cubic-bezier(.2, .75, .25, 1) both;
    }
    @keyframes xlink-card-enter {
        from { opacity: 0; transform: translateY(14px) scale(.985); }
        to { opacity: 1; transform: translateY(0) scale(1); }
    }
    .xlink-login-title {
        margin-bottom: 28px;
        color: #fff;
        font-size: 29px;
        font-weight: 750;
        letter-spacing: -.5px;
        text-shadow: 0 2px 20px rgba(0, 0, 0, .16);
    }
    .xlink-login-field {
        min-height: 49px;
        margin-bottom: 16px;
        grid-template-columns: 48px minmax(0, 1fr);
        border: 1px solid rgba(211, 229, 246, .62);
        border-radius: 13px;
        background: rgba(248, 252, 255, .97);
        transition: border-color .2s ease, box-shadow .2s ease, transform .2s ease;
    }
    .xlink-login-field:focus-within {
        border-color: #63d7e5;
        box-shadow: 0 0 0 3px rgba(72, 204, 222, .19);
        transform: translateY(-1px);
    }
    .xlink-login-field-icon {
        min-width: 48px;
        background: linear-gradient(145deg, #5c69dd, #169eae);
        color: #fff;
    }
    .xlink-login-field-icon svg { width: 17px; height: 17px; }
    .xlink-login-field input {
        height: 47px;
        padding: 10px 14px;
        background: transparent !important;
        color: #172d43 !important;
        font-size: 14px;
    }
    .xlink-login-field input::placeholder { color: #74869a; }
    .xlink-login-options {
        margin: 19px 0 20px;
        color: #e9f4ff;
        font-size: 13px;
    }
    .xlink-login-remember input {
        width: 17px;
        height: 17px;
        accent-color: #56c8d8;
    }
    .xlink-login-forgot {
        color: #83e2ee;
        font-weight: 600;
    }
    .xlink-login-forgot:hover { color: #fff; }
    .xlink-login-submit {
        width: 100%;
        min-height: 48px;
        padding: 11px 18px;
        border: 1px solid rgba(255, 255, 255, .22);
        border-radius: 13px;
        background: linear-gradient(105deg, #655ee8 0%, #328cc9 52%, #19aeb0 100%);
        box-shadow: 0 12px 28px rgba(35, 119, 184, .28);
        color: #fff;
        font-size: 14px;
        font-weight: 700;
        letter-spacing: .02em;
        transition: filter .2s ease, transform .2s ease, box-shadow .2s ease;
    }
    .xlink-login-submit:hover,
    .xlink-login-submit:focus {
        border-color: rgba(255, 255, 255, .42);
        background: linear-gradient(105deg, #716df0 0%, #399bd9 52%, #22bdba 100%);
        color: #fff;
        box-shadow: 0 16px 34px rgba(35, 119, 184, .36);
        transform: translateY(-2px);
    }
    .xlink-login-errors,
    .xlink-login-status {
        padding: 11px 13px;
        border-radius: 12px;
        background: rgba(255, 235, 235, .96);
        box-shadow: 0 8px 24px rgba(0, 0, 0, .10);
    }
    .xlink-login-status {
        border-color: rgba(95, 225, 164, .55);
        background: rgba(224, 255, 239, .96);
        color: #17663a;
    }
    @media (max-width: 900px) {
        .xlink-login-logo-circle { width: 150px; height: 150px; }
        .xlink-login-logo-frame { width: 92px; height: 92px; }
        .xlink-login-content { padding: 32px 28px 30px; }
    }
    @media (max-width: 640px) {
        .xlink-login-screen {
            grid-template-rows: 176px minmax(420px, 1fr);
            background:
                radial-gradient(ellipse at 50% 5%, rgba(32, 190, 207, .20), transparent 42%),
                linear-gradient(145deg, #071426 0%, #102945 55%, #10213c 100%);
        }
        .xlink-login-brand {
            padding: 18px 16px 28px;
            border-right: 0;
            border-bottom: 1px solid rgba(191, 226, 255, .15);
            background: rgba(5, 17, 33, .22);
        }
        .xlink-login-brand-inner { gap: 13px; }
        .xlink-login-logo-circle { width: 100px; height: 100px; }
        .xlink-login-logo-frame { width: 64px; height: 64px; border-radius: 14px; }
        .xlink-login-company { font-size: 21px; }
        .xlink-login-version { left: 12px; bottom: 8px; padding: 5px 8px; font-size: 9px; }
        .xlink-login-main { padding: 68px 18px 28px; }
        .xlink-client-registration { top: 14px; right: 16px; min-height: 32px; padding: 6px 11px; font-size: 12px; }
        .xlink-login-content { max-width: 440px; margin: 16px auto 0; padding: 30px 24px 28px; border-radius: 22px; }
        .xlink-login-title { font-size: 25px; margin-bottom: 22px; }
    }
    @media (max-width: 360px) {
        .xlink-login-screen { grid-template-rows: 160px minmax(410px, 1fr); }
        .xlink-login-logo-circle { width: 86px; height: 86px; }
        .xlink-login-logo-frame { width: 56px; height: 56px; }
        .xlink-login-company { font-size: 19px; }
        .xlink-login-main { padding-right: 14px; padding-left: 14px; }
        .xlink-login-content { padding-right: 19px; padding-left: 19px; }
    }
    @media (prefers-reduced-motion: reduce) {
        .xlink-login-screen::before,
        .xlink-login-screen::after,
        .xlink-login-content {
            animation: none !important;
        }
        .xlink-login-field,
        .xlink-login-submit,
        .xlink-client-registration {
            transition: none !important;
        }
    }



    /* SHAMPUR ONLINE HORIZONTAL BRAND — Production */
    .xlink-login-brand-inner {
        width: 100%;
        max-width: 460px;
        padding: 0 8px;
        gap: 16px;
    }
    .xlink-login-logo-circle {
        width: min(100%, 430px);
        height: auto;
        min-height: 0;
        flex: 0 1 auto;
        border-radius: 14px;
        background: transparent;
        border: 0;
        box-shadow: none;
        overflow: visible;
    }
    .xlink-login-logo-frame {
        width: 100%;
        height: auto;
        aspect-ratio: 760 / 294;
        border-radius: 14px;
        background: transparent;
        overflow: hidden;
        box-shadow: 0 16px 46px rgba(0, 0, 0, .22);
    }
    .xlink-login-logo-frame img {
        display: block;
        width: 100%;
        height: 100%;
        max-width: 100%;
        max-height: none;
        padding: 0;
        object-fit: contain;
        border-radius: 14px;
    }
    .xlink-login-company {
        display: none;
    }
    @media (max-width: 900px) {
        .xlink-login-logo-circle { width: min(100%, 360px); }
        .xlink-login-logo-frame { width: 100%; }
    }
    @media (max-width: 640px) {
        .xlink-login-brand { padding: 12px 16px 27px; }
        .xlink-login-brand-inner {
            flex-direction: column;
            justify-content: center;
            gap: 8px;
            margin-top: 0;
            padding: 0;
        }
        .xlink-login-logo-circle { width: min(100%, 360px); }
        .xlink-login-logo-frame { width: 100%; }
    }
    @media (max-width: 360px) {
        .xlink-login-logo-circle { width: min(100%, 300px); }
    }

</style>
<script>
    document.body.classList.add('xlink-login-page');
</script>
<main class="xlink-login-screen">
    <section class="xlink-login-brand" aria-label="Shampur Online">
        <div class="xlink-login-brand-inner">
            <div class="xlink-login-logo-circle">
                <div class="xlink-login-logo-frame">
                    @if(siteUrlSettings('site_logo'))
                        <img src="{{ site_image(siteUrlSettings('site_logo')) }}" alt="{{ siteUrlSettings('site_name') ?? 'X-Link Limited' }}">
                    @elseif(siteUrlSettings('site_icon'))
                        <img src="{{ site_image(siteUrlSettings('site_icon')) }}" alt="{{ siteUrlSettings('site_name') ?? 'X-Link Limited' }}">
                    @else
                        <img src="{{ asset('images/logo.png') }}" alt="X-Link Limited">
                    @endif
                </div>
            </div>
            <h1 class="xlink-login-company">{{ siteUrlSettings('site_name') ?? 'X-Link Limited' }}</h1>
        </div>
        <div class="xlink-login-version">v8.2.4</div>
    </section>

    <section class="xlink-login-main" aria-label="Sign in">
        @if(Route::has('register'))
            <a class="xlink-client-registration" href="{{ route('register') }}">Client Registration</a>
        @endif

        <div class="xlink-login-content">
            <h2 class="xlink-login-title">Login To Your Account</h2>

            @if(session('status'))
                <div class="xlink-login-status" role="status">{{ session('status') }}</div>
            @endif

            @if($errors->any())
                <div class="xlink-login-errors" role="alert">
                    <ul>
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" novalidate>
                @csrf
                <label class="xlink-login-field" for="email">
                    <span class="xlink-login-field-icon" aria-hidden="true">
                        <svg viewBox="0 0 16 16" fill="currentColor"><path d="M8 8a3 3 0 1 0 0-6 3 3 0 0 0 0 6ZM2 14s-1 0-1-1 1-4 7-4 7 3 7 4-1 1-1 1H2Z"/></svg>
                    </span>
                    <input id="email" type="text" name="email" value="{{ old('email') }}" required autocomplete="username" placeholder="Username" autofocus>
                </label>

                <label class="xlink-login-field" for="password">
                    <span class="xlink-login-field-icon" aria-hidden="true">
                        <svg viewBox="0 0 16 16" fill="currentColor"><path d="M3.5 11.5a3.5 3.5 0 1 1 2.8-1.4L5 11.4V13H3.5v1.5H1V12l3.1-3.1a3.5 3.5 0 0 1-.6 2.6ZM10.5 1a4.5 4.5 0 0 0-3.9 6.8l2.1-2.1a2 2 0 1 1 1.4 1.4L8 9.2A4.5 4.5 0 1 0 10.5 1Z"/></svg>
                    </span>
                    <input id="password" type="password" name="password" required autocomplete="current-password" placeholder="Password">
                </label>

                <div class="xlink-login-options">
                    <label class="xlink-login-remember" for="remember">
                        <input id="remember" type="checkbox" name="remember" value="1" @checked(old('remember'))>
                        <span>Remember Me</span>
                    </label>
                    @if(Route::has('password.request'))
                        <a class="xlink-login-forgot" href="{{ route('password.request') }}">Forgot Password?</a>
                    @endif
                </div>

                <button class="xlink-login-submit" type="submit">Login</button>
            </form>
        </div>
    </section>
</main>
</x-guest-layout>
