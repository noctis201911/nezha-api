<div class="nzo-card">
    <div class="nzo-ch"><h3>商品明细</h3><span class="nzo-badge b-gray">{{ count($nzoItems) }} 项</span></div>
    <div class="nzo-cb" style="padding:0;">
        <table class="nzo-tbl">
            <thead><tr><th>商品</th><th style="text-align:center;width:70px;">数量</th><th style="text-align:right;width:110px;">金额</th></tr></thead>
            <tbody>
                @foreach ($nzoItems as $nzit)
                    <tr>
                        <td>
                            <div class="nzo-item">
                                <img src="{{ $nzit['img'] }}" alt="">
                                <div>
                                    <div style="font-weight:700;">{{ $nzit['name'] }}</div>
                                    <div style="font-size:11.5px;color:var(--meta);margin-top:2px;">
                                        @if ($nzit['variation'] && $nzit['addons']){{ $nzit['variation'] }} · 加料: {{ $nzit['addons'] }}
                                        @elseif ($nzit['variation']){{ $nzit['variation'] }}
                                        @elseif ($nzit['addons'])加料: {{ $nzit['addons'] }}
                                        @else 无加料 @endif
                                    </div>
                                </div>
                            </div>
                        </td>
                        <td style="text-align:center;">x{{ $nzit['qty'] }}</td>
                        <td style="text-align:right;font-weight:700;">{{ \App\CentralLogics\Helpers::format_currency($nzit['amt']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
