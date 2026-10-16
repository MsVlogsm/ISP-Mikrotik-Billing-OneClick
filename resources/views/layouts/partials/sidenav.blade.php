<nav class="navbar navbar-light navbar-glass navbar-top navbar-expand-lg" data-double-top-nav="data-double-top-nav" style="display: none;">
    <div class="w-100">
        <div class="d-flex flex-between-center">
            {{-- logo and Site name --}}
            <button class="btn navbar-toggler-humburger-icon navbar-toggler me-1 me-sm-3" type="button" data-bs-toggle="collapse" data-bs-target="#navbarDoubleTop" aria-controls="navbarDoubleTop" aria-expanded="false" aria-label="Toggle Navigation">
                <span class="navbar-toggle-icon">
                    <span class="toggle-line"></span>
                </span>
            </button>
            <a class="navbar-brand me-1 me-sm-3" href="{{ route('dashboard') }}">
                <div class="d-flex align-items-center">
                    {{-- Check if site logo exists and display it, otherwise show site icon or name --}}
                    @if (siteUrlSettings('site_logo'))
                        <img class="me-2" style="width: 150px; height: auto; max-height: 45px; max-width: 100%; object-fit: contain; flex-shrink: 0;" src="{{ site_image(siteUrlSettings('site_logo')) }}" alt="logo"/>
                    @else
                        @if (siteUrlSettings('site_icon'))
                            <img class="me-2" src="{{ site_image(siteUrlSettings('site_icon')) }}" alt="" width="40" />
                            <span class="font-sans-serif text-success">{{ siteUrlSettings('site_name') ?? 'X-Link Limited' }}</span>
                        @else
                            <span class="font-sans-serif text-success">{{ siteUrlSettings('site_name') ?? 'X-Link Limited' }}</span>
                        @endif
                    @endif
                </div>
            </a>
            @include('layouts.partials.searchbar')
            @include('layouts.partials.userpanel')
        </div>

        <hr class="my-2 d-none d-lg-block" />

        <div class="collapse navbar-collapse scrollbar py-lg-2" id="navbarDoubleTop">
            @include('layouts.partials.navpanel')
        </div>
    </div>
</nav>

<nav class="navbar navbar-light navbar-glass navbar-top navbar-expand-lg" style="display: none;">
    <button class="btn navbar-toggler-humburger-icon navbar-toggler me-1 me-sm-3" type="button" data-bs-toggle="collapse" data-bs-target="#navbarStandard" aria-controls="navbarStandard" aria-expanded="false" aria-label="Toggle Navigation">
        <span class="navbar-toggle-icon">
            <span class="toggle-line"></span>
        </span>
    </button>
    <a class="navbar-brand me-1 me-sm-3" href="{{ route('dashboard') }}">
        <div class="d-flex align-items-center">
            @if (siteUrlSettings('site_logo'))
                <img class="me-2" style="width: 150px; height: auto; max-height: 45px; max-width: 100%; object-fit: contain; flex-shrink: 0;" src="{{ site_image(siteUrlSettings('site_logo')) }}" alt="logo"/>
            @else
                @if (siteUrlSettings('site_icon'))
                    <img class="me-2" src="{{ site_image(siteUrlSettings('site_icon')) }}" alt="" width="40" />
                    <span class="font-sans-serif text-success">{{ siteUrlSettings('site_name') ?? 'X-Link Limited' }}</span>
                @else
                    <span class="font-sans-serif text-success">{{ siteUrlSettings('site_name') ?? 'X-Link Limited' }}</span>
                @endif
            @endif
        </div>
    </a>
    <div class="collapse navbar-collapse scrollbar" id="navbarStandard">
        @include('layouts.partials.navpanel')
    </div>
    @include('layouts.partials.userpanel')
</nav>

<nav class="navbar navbar-light navbar-vertical navbar-expand-xl" style="display: none;">
    <script>
        var navbarStyle = localStorage.getItem("navbarStyle");
        if (navbarStyle && navbarStyle !== 'transparent') {
            document.querySelector('.navbar-vertical').classList.add(`navbar-${navbarStyle}`);
        }
    </script>
    <div class="d-flex align-items-center">
        <div class="toggle-icon-wrapper">
            <button class="btn navbar-toggler-humburger-icon navbar-vertical-toggle" data-bs-placement="left" title="Toggle Navigation">
                <span class="navbar-toggle-icon">
                    <span class="toggle-line"></span>
                </span>
            </button>
        </div>
        <a class="navbar-brand" href="{{ route('dashboard') }}">
            <div class="d-flex align-items-center {{ siteUrlSettings('site_logo') ? 'py-1' : 'py-3' }}">
                @if (siteUrlSettings('site_logo'))
                    <img class="me-2" style="width: 150px; height: auto; max-height: 45px; max-width: 100%; object-fit: contain; flex-shrink: 0;" src="{{ site_image(siteUrlSettings('site_logo')) }}" alt="logo"/>
                @else
                    @if (siteUrlSettings('site_icon'))
                        <img class="me-2" src="{{ site_image(siteUrlSettings('site_icon')) }}" alt="" width="40" />
                        <span class="font-sans-serif text-success">{{ siteUrlSettings('site_name') ?? 'X-Link Limited' }}</span>
                    @else
                        <span class="font-sans-serif text-success">{{ siteUrlSettings('site_name') ?? 'X-Link Limited' }}</span>
                    @endif
                @endif
            </div>
        </a>
    </div>
    <div class="collapse navbar-collapse" id="navbarVerticalCollapse">
        <div class="navbar-vertical-content scrollbar">
            @include('layouts.partials.sidebarpanel')
        </div>
    </div>
</nav>
