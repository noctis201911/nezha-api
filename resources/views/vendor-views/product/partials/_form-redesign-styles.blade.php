<style>
    .nz-product-page {
        background: #f5f6f8;
        min-height: calc(100vh - 64px);
        padding-bottom: 24px;
    }

    .nz-product-page .page-header {
        margin-bottom: 16px;
    }

    .nz-product-page .page-header-title {
        color: #1f2329;
        font-size: 20px;
        font-weight: 600;
    }

    .nz-product-form {
        display: grid;
        gap: 12px;
        max-width: 1180px;
        margin: 0 auto;
    }

    .nz-form-card {
        margin: 0;
        overflow: hidden;
        border: 1px solid #e7eaef;
        border-radius: 14px;
        background: #fff;
        box-shadow: 0 1px 3px rgba(23, 28, 38, .04);
    }

    .nz-form-card__header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 16px;
        padding: 16px 20px;
        border-bottom: 1px solid #f0f2f4;
    }

    .nz-form-card__header h2 {
        margin: 0;
        color: #1f2329;
        font-size: 16px;
        font-weight: 600;
        line-height: 1.4;
    }

    .nz-form-card__header p,
    .nz-form-card__header > span {
        margin: 3px 0 0;
        color: #9aa0a8;
        font-size: 12px;
        line-height: 1.5;
    }

    .nz-form-card__header > span {
        flex-shrink: 0;
    }

    .nz-form-card__body {
        padding: 20px;
    }

    .nz-product-form .input-label {
        color: #5a6069;
        font-size: 13px;
        font-weight: 500;
    }

    .nz-product-form .form-control {
        min-height: 40px;
        border-color: #e7eaef;
        border-radius: 9px;
        color: #1f2329;
        box-shadow: none;
    }

    .nz-product-form .form-control:focus {
        border-color: #1f2329;
        box-shadow: 0 0 0 2px rgba(31, 35, 41, .08);
    }

    .nz-product-form textarea.form-control {
        min-height: 88px;
        resize: vertical;
    }

    .nz-required {
        color: #ae4840;
    }

    .nz-basic-grid {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 180px;
        gap: 20px;
    }

    .nz-two-column,
    .nz-price-row,
    .nz-inline-fields {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .nz-image-field {
        min-width: 0;
    }

    .nz-image-field .card,
    .nz-image-field .upload-file,
    .nz-image-field .upload-file__img {
        box-shadow: none !important;
    }

    .nz-price-row {
        max-width: 680px;
    }

    .nz-template-row {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 4px;
        padding: 12px 0 16px;
        color: #9aa0a8;
        font-size: 12px;
    }

    .nz-template-chip,
    .nz-add-option {
        min-height: 36px;
        padding: 7px 12px;
        border: 1px solid #e7eaef;
        border-radius: 999px;
        background: #fff;
        color: #1f2329;
        font-size: 12px;
        font-weight: 500;
        cursor: pointer;
    }

    .nz-template-chip:hover,
    .nz-add-option:hover {
        border-color: #1f2329;
    }

    .nz-template-chip--custom {
        border: 1.5px solid #1f2329;
    }

    .nz-variation-list {
        display: grid;
        gap: 12px;
    }

    .nz-variation-group {
        padding: 16px;
        border: 1px solid #e7eaef;
        border-radius: 12px;
        background: #fff;
    }

    .nz-variation-group__top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 12px;
    }

    .nz-required-toggle {
        margin: 0;
        color: #5a6069;
        font-size: 12px;
    }

    .nz-variation-head-grid {
        display: grid;
        grid-template-columns: minmax(180px, 1fr) minmax(220px, auto) minmax(220px, .8fr);
        align-items: end;
        gap: 12px;
    }

    .nz-choice-mode {
        min-width: 0;
        margin: 0;
        padding: 0;
        border: 0;
    }

    .nz-choice-mode legend {
        margin-bottom: 7px;
        color: #5a6069;
        font-size: 13px;
        font-weight: 500;
    }

    .nz-segmented {
        display: inline-flex;
        min-height: 40px;
        overflow: hidden;
        border: 1px solid #e7eaef;
        border-radius: 9px;
    }

    .nz-segmented label {
        margin: 0;
        cursor: pointer;
    }

    .nz-segmented input {
        position: absolute;
        opacity: 0;
        pointer-events: none;
    }

    .nz-segmented span {
        display: flex;
        align-items: center;
        min-height: 40px;
        padding: 0 14px;
        color: #5a6069;
        font-size: 13px;
    }

    .nz-segmented input:checked + span {
        background: #1f2329;
        color: #fff;
        font-weight: 600;
    }

    .nz-segmented input:focus-visible + span {
        outline: 2px solid #1f2329;
        outline-offset: -2px;
    }

    .nz-min-max {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
    }

    .nz-option-columns,
    .nz-option-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) 150px 110px 36px;
        gap: 8px;
        align-items: center;
    }

    .nz-option-columns {
        margin-top: 16px;
        padding: 0 2px 6px;
        color: #9aa0a8;
        font-size: 12px;
    }

    .nz-option-list {
        display: grid;
        gap: 8px;
    }

    .nz-icon-button {
        width: 32px;
        height: 32px;
        padding: 0;
        border: 0;
        border-radius: 8px;
        background: transparent;
        color: #9aa0a8;
        font-size: 22px;
        line-height: 1;
        cursor: pointer;
    }

    .nz-icon-button:hover {
        background: #f9eae8;
        color: #ae4840;
    }

    .nz-add-option {
        margin-top: 10px;
        border-radius: 9px;
        border-style: dashed;
    }

    .nz-empty-inline {
        padding: 16px;
        border: 1px dashed #e7eaef;
        border-radius: 10px;
        color: #9aa0a8;
        font-size: 13px;
        text-align: center;
    }

    .nz-addon-row {
        display: grid;
        grid-template-columns: minmax(0, 1fr) auto;
        align-items: end;
        gap: 12px;
    }

    .nz-secondary-action {
        display: inline-flex;
        align-items: center;
        min-height: 40px;
        color: #1f2329;
        font-size: 13px;
        font-weight: 600;
        text-decoration: none;
        white-space: nowrap;
    }

    .nz-advanced-card summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        min-height: 56px;
        padding: 0 20px;
        color: #1f2329;
        cursor: pointer;
        list-style: none;
    }

    .nz-advanced-card summary::-webkit-details-marker {
        display: none;
    }

    .nz-advanced-card summary::after {
        content: '⌄';
        color: #9aa0a8;
        font-size: 18px;
        transition: transform .15s ease;
    }

    .nz-advanced-card[open] summary {
        border-bottom: 1px solid #f0f2f4;
    }

    .nz-advanced-card[open] summary::after {
        transform: rotate(180deg);
    }

    .nz-advanced-card summary > span:last-child {
        margin-left: auto;
        color: #9aa0a8;
        font-size: 12px;
    }

    .nz-advanced-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 12px;
    }

    .nz-toggle-row {
        display: flex;
        align-items: center;
        gap: 20px;
        min-height: 40px;
    }

    .nz-form-actions {
        position: sticky;
        bottom: 0;
        z-index: 10;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        padding: 12px 20px;
        border: 1px solid #e7eaef;
        border-radius: 12px;
        background: rgba(255, 255, 255, .96);
        box-shadow: 0 -6px 20px rgba(23, 28, 38, .06);
        backdrop-filter: blur(8px);
    }

    .nz-reset-button,
    .nz-submit-button {
        min-width: 88px;
        height: 40px;
        border-radius: 9px;
        font-weight: 600;
    }

    .nz-reset-button {
        border: 1.5px solid #1f2329;
        background: #fff;
        color: #1f2329;
    }

    .nz-submit-button {
        border: 1.5px solid #1f2329;
        background: #1f2329;
        color: #fff;
    }

    .nz-submit-button:hover,
    .nz-submit-button:focus {
        background: #3a4048;
        color: #fff;
    }

    .nz-submit-button:disabled {
        border-color: #c9cdd2;
        background: #c9cdd2;
        cursor: wait;
    }

    @media (max-width: 991px) {
        .nz-basic-grid,
        .nz-variation-head-grid,
        .nz-advanced-grid {
            grid-template-columns: 1fr 1fr;
        }

        .nz-image-field {
            grid-column: 2;
            grid-row: 1;
        }

        .nz-basic-fields {
            grid-column: 1;
        }

        .nz-choice-mode,
        .nz-min-max {
            grid-column: auto;
        }
    }

    @media (max-width: 767px) {
        .nz-form-card__header,
        .nz-form-card__body {
            padding: 14px;
        }

        .nz-basic-grid,
        .nz-two-column,
        .nz-price-row,
        .nz-variation-head-grid,
        .nz-advanced-grid,
        .nz-addon-row {
            grid-template-columns: 1fr;
        }

        .nz-image-field,
        .nz-basic-fields {
            grid-column: 1;
            grid-row: auto;
        }

        .nz-option-columns {
            display: none;
        }

        .nz-option-row {
            grid-template-columns: minmax(0, 1fr) 104px 36px;
            padding-top: 8px;
            border-top: 1px solid #f0f2f4;
        }

        .nz-option-row .nz-option-stock {
            grid-column: 1 / span 2;
        }

        .nz-option-row .nz-icon-button {
            grid-column: 3;
            grid-row: 1;
        }

        .nz-advanced-card summary > span:last-child,
        .nz-form-card__header > span {
            display: none;
        }

        .nz-form-actions {
            padding: 10px 14px;
        }
    }
</style>
