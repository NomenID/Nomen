<?php /*
 * Copyright (c) 2025-2026 Yunyun (Wang Yaoze)
 * Licensed under the GNU General Public License, version 3 only, with
 * additional attribution terms in the NOTICE file.
 * Source: https://github.com/NomenID/Nomen
 * Project: https://nomenid.org/
 */ ?>
<div class="site-sky" aria-hidden="true">
    <svg class="site-sky__scene" viewBox="0 0 1440 900" preserveAspectRatio="xMidYMid slice" focusable="false">
        <defs>
            <linearGradient id="site-sky-horizon" x1="0" y1="0" x2="1" y2="0">
                <stop offset="0" class="site-sky__fade-0"/>
                <stop offset="0.5" class="site-sky__fade-1"/>
                <stop offset="1" class="site-sky__fade-0"/>
            </linearGradient>
        </defs>

        <g class="site-sky__orbits">
            <ellipse cx="1150" cy="-40" rx="560" ry="210" transform="rotate(-18 1150 -40)" stroke-width="1.1" opacity=".3"/>
            <ellipse cx="1150" cy="-40" rx="680" ry="262" transform="rotate(-18 1150 -40)" stroke-width="1" opacity=".14"/>
            <ellipse cx="140" cy="950" rx="460" ry="190" transform="rotate(12 140 950)" stroke-width="1" opacity=".18"/>
        </g>

        <polygon class="site-sky__lines" points="1277,853 992,761 930,467 1153,267 1438,359 1500,653" stroke-width="1" opacity=".12"/>

        <g class="site-sky__lines" stroke-width=".8">
            <polyline points="268,178 346,126 430,190 404,272 314,252" opacity=".26"/>
            <polyline points="1048,724 1128,780 1220,736 1300,806" opacity=".18"/>
        </g>

        <g class="site-sky__stars">
            <circle cx="120" cy="96" r="1.2" opacity=".5"/>
            <circle cx="208" cy="62" r=".8" opacity=".3"/>
            <circle class="site-sky__twinkle site-sky__twinkle--b" cx="318" cy="108" r=".9" opacity=".35"/>
            <circle cx="420" cy="74" r="1.1" opacity=".42"/>
            <circle cx="980" cy="88" r="1" opacity=".38"/>
            <circle class="site-sky__twinkle" cx="1062" cy="128" r="1.4" opacity=".55"/>
            <circle cx="1168" cy="72" r=".8" opacity=".3"/>
            <circle cx="1288" cy="142" r="1.2" opacity=".48"/>
            <circle cx="1372" cy="96" r=".9" opacity=".32"/>

            <circle cx="74" cy="302" r="1" opacity=".3"/>
            <circle class="site-sky__twinkle site-sky__twinkle--c" cx="148" cy="420" r="1.3" opacity=".45"/>
            <circle cx="62" cy="520" r=".8" opacity=".28"/>
            <circle cx="232" cy="596" r="1.1" opacity=".4"/>
            <circle cx="120" cy="700" r="1.4" opacity=".5"/>
            <circle cx="322" cy="472" r=".9" opacity=".32"/>
            <circle cx="386" cy="642" r="1.2" opacity=".42"/>
            <circle cx="250" cy="762" r=".8" opacity=".3"/>
            <circle class="site-sky__twinkle site-sky__twinkle--b" cx="86" cy="834" r="1.1" opacity=".38"/>
            <circle cx="356" cy="836" r=".9" opacity=".3"/>

            <circle cx="1036" cy="318" r=".9" opacity=".32"/>
            <circle cx="1118" cy="432" r="1.2" opacity=".45"/>
            <circle cx="1240" cy="382" r=".8" opacity=".28"/>
            <circle cx="1332" cy="486" r="1.3" opacity=".46"/>
            <circle cx="1078" cy="562" r="1" opacity=".36"/>
            <circle class="site-sky__twinkle" cx="1186" cy="622" r="1.4" opacity=".5"/>
            <circle cx="1304" cy="604" r=".9" opacity=".3"/>
            <circle class="site-sky__twinkle site-sky__twinkle--c" cx="1392" cy="706" r="1.1" opacity=".4"/>
            <circle cx="1124" cy="742" r=".8" opacity=".3"/>
            <circle cx="1246" cy="802" r="1.2" opacity=".42"/>
            <circle cx="1346" cy="858" r=".9" opacity=".3"/>
        </g>

        <g class="site-sky__stars" stroke-width=".8">
            <circle cx="268" cy="178" r="1.7" opacity=".55"/>
            <circle cx="346" cy="126" r="1.9" opacity=".7"/>
            <circle cx="430" cy="190" r="1.5" opacity=".5"/>
            <circle cx="404" cy="272" r="1.4" opacity=".45"/>
            <circle cx="314" cy="252" r="1.3" opacity=".4"/>
            <circle cx="1048" cy="724" r="1.7" opacity=".45"/>
            <circle cx="1128" cy="780" r="1.5" opacity=".4"/>
            <circle cx="1220" cy="736" r="1.3" opacity=".35"/>
            <circle cx="1300" cy="806" r="1.6" opacity=".42"/>
        </g>

        <g class="site-sky__stars">
            <path transform="translate(346 126) scale(.5)" d="M0 -9 L1.8 -1.8 L9 0 L1.8 1.8 L0 9 L-1.8 1.8 L-9 0 L-1.8 -1.8 Z" opacity=".6"/>
            <path transform="translate(1288 236) scale(.4)" d="M0 -9 L1.8 -1.8 L9 0 L1.8 1.8 L0 9 L-1.8 1.8 L-9 0 L-1.8 -1.8 Z" opacity=".45"/>
        </g>

        <path class="site-sky__horizon" d="M -200 940 C 400 830, 1040 830, 1640 940" stroke-width="1.2" opacity=".55"/>
        <path class="site-sky__horizon" d="M -200 988 C 400 878, 1040 878, 1640 988" stroke-width="1" opacity=".25"/>
    </svg>
    <svg class="site-boat" viewBox="-44 -50 88 84" focusable="false">
        <g class="site-sky__boat">
            <path d="M -32 -5 C -20 1, 20 1, 32 -5 L 24 6 C 10 11, -10 11, -24 6 Z"/>
            <path d="M 0 -4 V -38"/>
            <path d="M -16 -31 H 16"/>
            <path d="M 0 -38 L -25 -6"/>
            <path d="M 0 -38 L 25 -6"/>
            <path d="M -18 19 H 18" opacity=".45"/>
        </g>
    </svg>
</div>
