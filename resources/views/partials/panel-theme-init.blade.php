@php
    $scheme = config('getfy.panel_color_scheme', \App\Support\PanelColorScheme::defaults());
    $path = request()->path();
    $guestAuthPaths = ['login', 'cadastro', 'criar-admin', 'esqueci-senha', 'login/2fa'];
    $ignoreStoredTheme = ! auth()->check() && (
        in_array($path, $guestAuthPaths, true)
        || str_starts_with($path, 'redefinir-senha/')
        || str_starts_with($path, 'plataforma/login')
    );
    // Paynext DS: checkout (feito para fundo claro) no vidro claro; área de membros no vidro escuro.
    $forcedTheme = null;
    if (str_starts_with($path, 'c/') || str_starts_with($path, 'checkout') || str_starts_with($path, 'api-checkout') || str_starts_with($path, 'pagar')) {
        $forcedTheme = 'light';
    } elseif (str_starts_with($path, 'm/') || request()->attributes->get('member_area_slug')) {
        $forcedTheme = 'dark';
    }
@endphp
<script>
(function(){try{
    var policy=@json($scheme);
    var ignoreStored=@json($ignoreStoredTheme);
    var forced=@json($forcedTheme);
    if(forced){document.documentElement.classList.toggle('dark',forced==='dark');return;}
    var isDark=false;
    var stored=null;
    if(!policy.locked&&!ignoreStored){stored=localStorage.getItem('theme');}
    if(policy.locked){
        if(policy.mode==='system'){isDark=window.matchMedia('(prefers-color-scheme: dark)').matches;}
        else{isDark=policy.mode==='dark';}
    }else if(stored==='light'||stored==='dark'){isDark=stored==='dark';}
    else if(policy.mode==='system'){isDark=window.matchMedia('(prefers-color-scheme: dark)').matches;}
    else{isDark=policy.mode==='dark';}
    document.documentElement.classList.toggle('dark',isDark);
}catch(_){}})();
</script>
