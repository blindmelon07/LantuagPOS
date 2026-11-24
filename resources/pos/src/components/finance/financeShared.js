import React from "react";
import { useSelector } from "react-redux";
import { useIntl } from "react-intl";
import { Card } from "react-bootstrap";
import moment from "moment";
import apiConfig from "../../config/apiConfig";
import { currencySymbolHandling } from "../../shared/sharedMethod";

/** Shared helpers for the KPI and Accounting screens. */

export const financeApi = (url, params = {}) =>
    apiConfig.get(url, { params }).then((response) => response.data.data);

export const defaultRange = () => ({
    start_date: moment().startOf("month").format("YYYY-MM-DD"),
    end_date: moment().format("YYYY-MM-DD"),
});

/** Turns a DateRangePicker selection into {start_date, end_date}; "clear" falls back to this month. */
export const rangeFromPicker = (date) =>
    date?.params?.start_date && date?.params?.end_date
        ? { start_date: date.params.start_date, end_date: date.params.end_date }
        : defaultRange();

export const useT = () => {
    const intl = useIntl();
    return (id, values) => intl.formatMessage({ id, defaultMessage: id }, values);
};

export const useMoney = () => {
    const { allConfigData, frontSetting } = useSelector((state) => state);
    const symbol = frontSetting?.value?.currency_symbol || "";
    return (value) => currencySymbolHandling(allConfigData, symbol, Number(value || 0));
};

export const ChangeBadge = ({ value, inverse = false }) => {
    if (value === null || value === undefined) {
        return <span className="text-gray-500 small">—</span>;
    }
    const good = inverse ? value <= 0 : value >= 0;
    return (
        <span className={`badge ${good ? "bg-light-success text-success" : "bg-light-danger text-danger"} small`}>
            {value >= 0 ? "▲" : "▼"} {Math.abs(value)}%
        </span>
    );
};

export const StatCard = ({ title, value, sub, change, inverse }) => (
    <Card className="h-100 border-0 shadow-sm">
        <Card.Body className="p-4">
            <div className="text-gray-600 fs-6 mb-2">{title}</div>
            <div className="fs-2 fw-bolder text-gray-900 text-break">{value}</div>
            <div className="d-flex align-items-center gap-2 mt-2 flex-wrap">
                {change !== undefined && <ChangeBadge value={change} inverse={inverse} />}
                {sub && <span className="text-gray-500 small">{sub}</span>}
            </div>
        </Card.Body>
    </Card>
);

/** Horizontal stacked bar of the four aging buckets. */
export const AgingBar = ({ aging, money, t }) => {
    const buckets = [
        { key: "0_30", label: t("accounting.aging.0_30"), color: "#50cd89" },
        { key: "31_60", label: t("accounting.aging.31_60"), color: "#ffc700" },
        { key: "61_90", label: t("accounting.aging.61_90"), color: "#fd7e14" },
        { key: "over_90", label: t("accounting.aging.over_90"), color: "#f1416c" },
    ];
    const total = buckets.reduce((sum, b) => sum + Number(aging?.[b.key] || 0), 0);
    return (
        <div>
            <div className="d-flex rounded overflow-hidden mb-3" style={{ height: 12, background: "#eff2f5" }}>
                {total > 0 &&
                    buckets.map((b) => (
                        <div key={b.key} style={{ width: `${(Number(aging?.[b.key] || 0) / total) * 100}%`, background: b.color }} />
                    ))}
            </div>
            <div className="row g-3">
                {buckets.map((b) => (
                    <div className="col-6 col-md-3" key={b.key}>
                        <div className="d-flex align-items-center gap-2 text-gray-600 small">
                            <span className="rounded-circle d-inline-block" style={{ width: 10, height: 10, background: b.color }} />
                            {b.label}
                        </div>
                        <div className="fw-bold fs-5">{money(aging?.[b.key])}</div>
                    </div>
                ))}
            </div>
        </div>
    );
};

export const Loading = () => (
    <div className="text-center py-10">
        <div className="spinner-border text-primary" role="status" />
    </div>
);

export const EmptyRow = ({ colSpan, text }) => (
    <tr>
        <td colSpan={colSpan} className="text-center text-gray-500 py-6">
            {text}
        </td>
    </tr>
);
