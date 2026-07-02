@php
    $map = [
        'applied'     => ['待审批', 'warning'],
        'kyc_pending' => ['待身份核验', 'secondary'],
        'approved'    => ['已审批·待放款', 'info'],
        'paying'      => ['放款中', 'info'],
        'partial'     => ['部分放款', 'warning'],
        'paid'        => ['已结清', 'success'],
        'owing'       => ['欠款待清缴', 'danger'],
        'rejected'    => ['已拒绝', 'danger'],
        'withdrawn'   => ['已撤回', 'secondary'],
        'failed'      => ['熔断·转人工', 'danger'],
    ];
    $pair = $map[$st] ?? [$st, 'secondary'];
@endphp
<span class="badge badge-soft-{{ $pair[1] }}">{{ translate($pair[0]) }}</span>
