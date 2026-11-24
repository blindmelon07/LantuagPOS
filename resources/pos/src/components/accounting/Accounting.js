import React, { useEffect, useState, useCallback } from "react";
import { Card, Col, Row, Table, Button } from "react-bootstrap";
import { Link } from "react-router-dom";
import { Bar } from "react-chartjs-2";
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Tooltip, Legend } from "chart.js";
import moment from "moment";
import MasterLayout from "../MasterLayout";
import TabTitle from "../../shared/tab-title/TabTitle";
import TopProgressBar from "../../shared/components/loaders/TopProgressBar";
import DateRangePicker from "../../shared/datepicker/DateRangePicker";
import { financeApi, defaultRange, rangeFromPicker, useT, useMoney, StatCard, AgingBar, Loading, EmptyRow } from "../finance/financeShared";
import SupplierPaymentModal from "./SupplierPaymentModal";

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend);

const TABS = ["profit-loss", "receivables", "payables", "cash-flow", "cash-book", "vat"];
const DATED_TABS = ["profit-loss", "cash-flow", "cash-book", "vat"];
/** Top rule for total rows (border-2 would box the whole row). */
const TOTAL = { borderTop: "2px solid #e4e6ef" };

/* ---------------------------------------------------------------- Profit & loss */
const ProfitLoss = ({ data, money, t }) => {
    const line = (label, value, { bold, negative, indent, border } = {}) => (
        <tr style={border ? TOTAL : undefined}>
            <td className={`${bold ? "fw-bolder" : ""} ${indent ? "ps-8 text-gray-600" : ""}`}>{label}</td>
            <td className={`text-end ${bold ? "fw-bolder" : ""} ${negative ? "text-danger" : ""}`}>
                {negative ? `(${money(value)})` : money(value)}
            </td>
        </tr>
    );
    return (
        <Row className="g-4">
            <Col lg={8}>
                <Card className="border-0 shadow-sm">
                    <Card.Body>
                        <Table responsive className="mb-0 align-middle">
                            <tbody>
                                {line(t("accounting.pl.gross-sales"), data.gross_sales)}
                                {line(t("accounting.pl.sales-returns"), data.sales_returns, { negative: true, indent: true })}
                                {line(t("accounting.pl.net-sales"), data.net_sales, { bold: true, border: true })}
                                {line(t("accounting.pl.output-vat"), data.output_tax, { negative: true, indent: true })}
                                {line(t("accounting.pl.net-revenue"), data.net_revenue, { bold: true, border: true })}
                                {line(t("accounting.pl.cogs"), data.cogs, { negative: true, indent: true })}
                                {line(t("accounting.pl.gross-profit"), data.gross_profit, { bold: true, border: true })}
                                <tr>
                                    <td className="fw-bold pt-6" colSpan={2}>
                                        {t("accounting.pl.operating-expenses")}
                                    </td>
                                </tr>
                                {data.expenses.length === 0 && (
                                    <tr>
                                        <td className="ps-8 text-gray-500" colSpan={2}>
                                            {t("accounting.pl.no-expenses")}
                                        </td>
                                    </tr>
                                )}
                                {data.expenses.map((e) => line(e.category, e.amount, { negative: true, indent: true }))}
                                {line(t("accounting.pl.total-expenses"), data.total_expenses, { bold: true, negative: true, border: true })}
                                {line(t("accounting.pl.net-profit"), data.net_profit, { bold: true, border: true })}
                            </tbody>
                        </Table>
                    </Card.Body>
                </Card>
            </Col>
            <Col lg={4}>
                <Row className="g-4">
                    <Col xs={12}>
                        <StatCard title={t("accounting.pl.gross-margin")} value={`${data.gross_margin}%`} />
                    </Col>
                    <Col xs={12}>
                        <StatCard title={t("accounting.pl.net-margin")} value={`${data.net_margin}%`} />
                    </Col>
                    <Col xs={12}>
                        <div className="text-gray-600 small">{t("accounting.pl.cost-note")}</div>
                    </Col>
                </Row>
            </Col>
        </Row>
    );
};

/* ---------------------------------------------------------------- Receivables / payables */
const OpenItems = ({ data, money, t, kind, onPay }) => {
    const isPayable = kind === "payables";
    const detailUrl = (id) => (isPayable ? `/app/user/purchases/detail/${id}` : `/app/user/sales/detail/${id}`);
    return (
        <>
            <Row className="g-4 mb-5">
                <Col lg={4}>
                    <StatCard
                        title={isPayable ? t("accounting.payables.total") : t("accounting.receivables.total")}
                        value={money(data.total)}
                        sub={t("accounting.documents-open", { count: data.documents.length })}
                    />
                </Col>
                <Col lg={8}>
                    <Card className="h-100 border-0 shadow-sm">
                        <Card.Body>
                            <div className="fw-bold mb-3">{t("accounting.aging.title")}</div>
                            <AgingBar aging={data.aging} money={money} t={t} />
                        </Card.Body>
                    </Card>
                </Col>
            </Row>
            <Card className="border-0 shadow-sm mb-5">
                <Card.Header className="border-0 pt-5">
                    <h3 className="mb-0">{isPayable ? t("accounting.by-supplier") : t("accounting.by-customer")}</h3>
                </Card.Header>
                <Card.Body className="pt-0">
                    <Table responsive className="align-middle mb-0">
                        <thead>
                            <tr className="text-gray-600">
                                <th>{isPayable ? t("accounting.supplier") : t("accounting.customer")}</th>
                                <th>{t("accounting.phone")}</th>
                                <th className="text-end">{t("accounting.aging.0_30")}</th>
                                <th className="text-end">{t("accounting.aging.31_60")}</th>
                                <th className="text-end">{t("accounting.aging.61_90")}</th>
                                <th className="text-end">{t("accounting.aging.over_90")}</th>
                                <th className="text-end">{t("accounting.balance")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {data.parties.length === 0 ? (
                                <EmptyRow colSpan={7} text={isPayable ? t("accounting.payables.none") : t("accounting.receivables.none")} />
                            ) : (
                                data.parties.map((p) => (
                                    <tr key={p.party_id ?? "none"}>
                                        <td className="fw-bold">{p.party}</td>
                                        <td>{p.phone || "—"}</td>
                                        {["0_30", "31_60", "61_90", "over_90"].map((b) => (
                                            <td key={b} className={`text-end ${b === "over_90" && p.aging[b] > 0 ? "text-danger fw-bold" : ""}`}>
                                                {p.aging[b] ? money(p.aging[b]) : "—"}
                                            </td>
                                        ))}
                                        <td className="text-end fw-bolder">{money(p.balance)}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </Table>
                </Card.Body>
            </Card>
            <Card className="border-0 shadow-sm">
                <Card.Header className="border-0 pt-5">
                    <h3 className="mb-0">{t("accounting.open-documents")}</h3>
                </Card.Header>
                <Card.Body className="pt-0">
                    <Table responsive className="align-middle mb-0">
                        <thead>
                            <tr className="text-gray-600">
                                <th>{t("accounting.reference")}</th>
                                <th>{t("accounting.date")}</th>
                                <th>{isPayable ? t("accounting.supplier") : t("accounting.customer")}</th>
                                <th className="text-end">{t("accounting.days")}</th>
                                <th className="text-end">{t("accounting.total")}</th>
                                <th className="text-end">{t("accounting.paid")}</th>
                                <th className="text-end">{t("accounting.balance")}</th>
                                {isPayable && <th />}
                            </tr>
                        </thead>
                        <tbody>
                            {data.documents.length === 0 ? (
                                <EmptyRow colSpan={isPayable ? 8 : 7} text={isPayable ? t("accounting.payables.none") : t("accounting.receivables.none")} />
                            ) : (
                                data.documents.map((d) => (
                                    <tr key={d.id}>
                                        <td>
                                            <Link to={detailUrl(d.id)}>{d.reference}</Link>
                                        </td>
                                        <td>{d.date}</td>
                                        <td>{d.party}</td>
                                        <td className={`text-end ${d.days > 90 ? "text-danger fw-bold" : ""}`}>{d.days}</td>
                                        <td className="text-end">{money(d.total)}</td>
                                        <td className="text-end">{money(d.paid)}</td>
                                        <td className="text-end fw-bolder">{money(d.balance)}</td>
                                        {isPayable && (
                                            <td className="text-end">
                                                <Button size="sm" onClick={() => onPay(d)}>
                                                    {t("accounting.pay")}
                                                </Button>
                                            </td>
                                        )}
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </Table>
                </Card.Body>
            </Card>
        </>
    );
};

/* ---------------------------------------------------------------- Cash */
const TYPE_LABELS = {
    sale_payment: "accounting.cash.sale-payment",
    purchase_payment: "accounting.cash.purchase-payment",
    purchase: "accounting.cash.purchase",
    expense: "accounting.cash.expense",
    sale_refund: "accounting.cash.sale-refund",
};

const CashSummary = ({ data, money, t }) => (
    <Row className="g-4 mb-5">
        <Col xs={12} sm={6} xl={3}>
            <StatCard title={t("accounting.cash.opening")} value={money(data.opening_balance)} />
        </Col>
        <Col xs={12} sm={6} xl={3}>
            <StatCard title={t("accounting.cash.in")} value={<span className="text-success">{money(data.total_in)}</span>} />
        </Col>
        <Col xs={12} sm={6} xl={3}>
            <StatCard title={t("accounting.cash.out")} value={<span className="text-danger">{money(data.total_out)}</span>} />
        </Col>
        <Col xs={12} sm={6} xl={3}>
            <StatCard title={t("accounting.cash.closing")} value={money(data.closing_balance)} />
        </Col>
    </Row>
);

const CashFlow = ({ data, money, t }) => {
    const label = (p) => (data.grouped_by === "month" ? moment(p + "-01").format("MMM YYYY") : moment(p).format("MMM D"));
    const chartData = {
        labels: data.series.map((s) => label(s.period)),
        datasets: [
            { label: t("accounting.cash.in"), data: data.series.map((s) => s.in), backgroundColor: "rgba(80, 205, 137, 0.8)", borderRadius: 4 },
            { label: t("accounting.cash.out"), data: data.series.map((s) => s.out), backgroundColor: "rgba(241, 65, 108, 0.8)", borderRadius: 4 },
        ],
    };
    return (
        <>
            <CashSummary data={data} money={money} t={t} />
            <Row className="g-4">
                <Col xl={8}>
                    <Card className="h-100 border-0 shadow-sm">
                        <Card.Body>
                            <div style={{ height: 320 }}>
                                <Bar data={chartData} options={{ maintainAspectRatio: false }} />
                            </div>
                        </Card.Body>
                    </Card>
                </Col>
                <Col xl={4}>
                    <Card className="h-100 border-0 shadow-sm">
                        <Card.Body>
                            <Table responsive className="mb-0 align-middle">
                                <thead>
                                    <tr className="text-gray-600">
                                        <th>{t("accounting.cash.source")}</th>
                                        <th className="text-end">{t("accounting.cash.in")}</th>
                                        <th className="text-end">{t("accounting.cash.out")}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {Object.keys(data.by_type).length === 0 ? (
                                        <EmptyRow colSpan={3} text={t("accounting.cash.none")} />
                                    ) : (
                                        Object.entries(data.by_type).map(([type, v]) => (
                                            <tr key={type}>
                                                <td>{t(TYPE_LABELS[type] || type)}</td>
                                                <td className="text-end text-success">{v.in ? money(v.in) : "—"}</td>
                                                <td className="text-end text-danger">{v.out ? money(v.out) : "—"}</td>
                                            </tr>
                                        ))
                                    )}
                                    <tr className="fw-bolder" style={TOTAL}>
                                        <td>{t("accounting.cash.net")}</td>
                                        <td className="text-end" colSpan={2}>
                                            {money(data.net)}
                                        </td>
                                    </tr>
                                </tbody>
                            </Table>
                        </Card.Body>
                    </Card>
                </Col>
            </Row>
        </>
    );
};

const CashBook = ({ data, money, t }) => (
    <>
        <CashSummary data={data} money={money} t={t} />
        <Card className="border-0 shadow-sm">
            <Card.Header className="border-0 pt-5 d-flex justify-content-between align-items-center">
                <h3 className="mb-0">{t("accounting.tab.cash-book")}</h3>
                <Button variant="outline-primary" size="sm" onClick={() => window.print()}>
                    {t("accounting.print")}
                </Button>
            </Card.Header>
            <Card.Body className="pt-0">
                <Table responsive className="align-middle mb-0">
                    <thead>
                        <tr className="text-gray-600">
                            <th>{t("accounting.date")}</th>
                            <th>{t("accounting.cash.source")}</th>
                            <th>{t("accounting.reference")}</th>
                            <th>{t("accounting.description")}</th>
                            <th className="text-end">{t("accounting.cash.in")}</th>
                            <th className="text-end">{t("accounting.cash.out")}</th>
                            <th className="text-end">{t("accounting.balance")}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr className="fw-bold bg-light">
                            <td colSpan={6}>{t("accounting.cash.opening")}</td>
                            <td className="text-end">{money(data.opening_balance)}</td>
                        </tr>
                        {data.rows.length === 0 ? (
                            <EmptyRow colSpan={7} text={t("accounting.cash.none")} />
                        ) : (
                            data.rows.map((r, i) => (
                                <tr key={i}>
                                    <td>{r.date}</td>
                                    <td>{t(TYPE_LABELS[r.type] || r.type)}</td>
                                    <td>{r.reference}</td>
                                    <td>{r.party}</td>
                                    <td className="text-end text-success">{r.in ? money(r.in) : ""}</td>
                                    <td className="text-end text-danger">{r.out ? money(r.out) : ""}</td>
                                    <td className="text-end fw-bold">{money(r.balance)}</td>
                                </tr>
                            ))
                        )}
                        <tr className="fw-bolder" style={TOTAL}>
                            <td colSpan={4}>{t("accounting.cash.closing")}</td>
                            <td className="text-end text-success">{money(data.total_in)}</td>
                            <td className="text-end text-danger">{money(data.total_out)}</td>
                            <td className="text-end">{money(data.closing_balance)}</td>
                        </tr>
                    </tbody>
                </Table>
            </Card.Body>
        </Card>
    </>
);

/* ---------------------------------------------------------------- VAT */
const Vat = ({ data, money, t }) => (
    <Row className="g-4">
        <Col lg={7}>
            <Card className="border-0 shadow-sm">
                <Card.Body>
                    <Table responsive className="mb-0 align-middle">
                        <thead>
                            <tr className="text-gray-600">
                                <th />
                                <th className="text-end">{t("accounting.vat.gross-amount")}</th>
                                <th className="text-end">{t("accounting.vat.vat")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>{t("accounting.vat.sales")}</td>
                                <td className="text-end">{money(data.sales_total)}</td>
                                <td className="text-end">{money(data.output_tax)}</td>
                            </tr>
                            <tr>
                                <td>{t("accounting.vat.purchases")}</td>
                                <td className="text-end">{money(data.purchases_total)}</td>
                                <td className="text-end">({money(data.input_tax)})</td>
                            </tr>
                            <tr className="fw-bolder" style={TOTAL}>
                                <td>{data.vat_payable >= 0 ? t("accounting.vat.payable") : t("accounting.vat.excess")}</td>
                                <td />
                                <td className={`text-end ${data.vat_payable >= 0 ? "text-danger" : "text-success"}`}>{money(Math.abs(data.vat_payable))}</td>
                            </tr>
                        </tbody>
                    </Table>
                </Card.Body>
            </Card>
        </Col>
        <Col lg={5}>
            <div className="text-gray-600">{t("accounting.vat.note")}</div>
        </Col>
    </Row>
);

/* ---------------------------------------------------------------- page */
const Accounting = () => {
    const t = useT();
    const money = useMoney();
    const [tab, setTab] = useState("profit-loss");
    const [range, setRange] = useState(defaultRange());
    const [data, setData] = useState({});
    const [loading, setLoading] = useState(false);
    const [payDocument, setPayDocument] = useState(null);

    const load = useCallback(() => {
        setLoading(true);
        financeApi(`accounting/${tab === "vat" ? "vat" : tab}`, DATED_TABS.includes(tab) ? range : {})
            .then((result) => setData((prev) => ({ ...prev, [tab]: result })))
            .finally(() => setLoading(false));
    }, [tab, range]);

    useEffect(() => {
        load();
    }, [load]);

    const current = data[tab];
    const body = () => {
        if (!current) return <Loading />;
        switch (tab) {
            case "profit-loss":
                return <ProfitLoss data={current} money={money} t={t} />;
            case "receivables":
            case "payables":
                return <OpenItems data={current} money={money} t={t} kind={tab} onPay={setPayDocument} />;
            case "cash-flow":
                return <CashFlow data={current} money={money} t={t} />;
            case "cash-book":
                return <CashBook data={current} money={money} t={t} />;
            default:
                return <Vat data={current} money={money} t={t} />;
        }
    };

    return (
        <MasterLayout>
            <TopProgressBar />
            <TabTitle title={t("accounting.title")} />
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-5">
                <div>
                    <h1 className="mb-1">{t("accounting.title")}</h1>
                    <div className="text-gray-600">
                        {DATED_TABS.includes(tab) ? `${range.start_date} – ${range.end_date}` : t("accounting.as-of-today")}
                    </div>
                </div>
                {DATED_TABS.includes(tab) && <DateRangePicker onDateSelector={(date) => setRange(rangeFromPicker(date))} isProfitReport={true} />}
            </div>

            <div className="d-flex flex-wrap gap-2 mb-5" role="tablist">
                {TABS.map((key) => (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        aria-selected={tab === key}
                        className={`btn btn-sm ${tab === key ? "btn-primary" : "btn-outline-primary bg-white"}`}
                        onClick={() => setTab(key)}
                    >
                        {t(`accounting.tab.${key}`)}
                    </button>
                ))}
            </div>

            <div style={{ opacity: loading && current ? 0.6 : 1 }}>{body()}</div>

            <SupplierPaymentModal
                document={payDocument}
                onHide={() => setPayDocument(null)}
                onSaved={() => {
                    setPayDocument(null);
                    load();
                }}
            />
        </MasterLayout>
    );
};

export default Accounting;
