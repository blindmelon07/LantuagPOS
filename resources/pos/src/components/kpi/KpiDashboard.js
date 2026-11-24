import React, { useEffect, useState, useCallback } from "react";
import { Card, Col, Row, Table, ProgressBar, Button } from "react-bootstrap";
import { Bar } from "react-chartjs-2";
import { Chart as ChartJS, CategoryScale, LinearScale, BarElement, Tooltip, Legend } from "chart.js";
import moment from "moment";
import MasterLayout from "../MasterLayout";
import TabTitle from "../../shared/tab-title/TabTitle";
import TopProgressBar from "../../shared/components/loaders/TopProgressBar";
import DateRangePicker from "../../shared/datepicker/DateRangePicker";
import { financeApi, defaultRange, rangeFromPicker, useT, useMoney, StatCard, Loading, EmptyRow } from "../finance/financeShared";
import KpiTargetsModal from "./KpiTargetsModal";

ChartJS.register(CategoryScale, LinearScale, BarElement, Tooltip, Legend);

const TargetRow = ({ target, money, t }) => {
    const progress = Math.min(target.sales_progress || 0, 100);
    const onTrack = (target.sales_progress || 0) >= target.expected_progress;
    return (
        <div className="mb-6">
            <div className="d-flex justify-content-between flex-wrap gap-2 mb-2">
                <div className="fw-bold">{target.user_id ? target.name : t("kpi.targets.whole-store")}</div>
                <div className="text-gray-600">
                    {money(target.sales_actual)} / {money(target.sales_target)}
                    <span className={`ms-2 badge ${onTrack ? "bg-light-success text-success" : "bg-light-danger text-danger"}`}>
                        {onTrack ? t("kpi.targets.on-track") : t("kpi.targets.behind")}
                    </span>
                </div>
            </div>
            <div className="position-relative">
                <ProgressBar now={progress} label={`${target.sales_progress ?? 0}%`} variant={onTrack ? "success" : "warning"} style={{ height: 18 }} />
                <div
                    title={t("kpi.targets.expected")}
                    className="position-absolute top-0 h-100 border-start border-2 border-dark"
                    style={{ left: `${Math.min(target.expected_progress, 100)}%` }}
                />
            </div>
            {target.transactions_target ? (
                <div className="text-gray-600 small mt-1">
                    {t("kpi.transactions")}: {target.transactions_actual} / {target.transactions_target} ({target.transactions_progress}%)
                </div>
            ) : null}
        </div>
    );
};

const KpiDashboard = () => {
    const t = useT();
    const money = useMoney();
    const [range, setRange] = useState(defaultRange());
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [showTargets, setShowTargets] = useState(false);

    const load = useCallback(() => {
        setLoading(true);
        financeApi("kpi/summary", { ...range, period: moment(range.end_date).format("YYYY-MM") })
            .then(setData)
            .finally(() => setLoading(false));
    }, [range]);

    useEffect(() => {
        load();
    }, [load]);

    const c = data?.current || {};
    const ch = data?.change || {};
    const period = moment(range.end_date).format("YYYY-MM");

    const chartData = {
        labels: (data?.series || []).map((s) =>
            data?.series?.length && s.period.length === 7 ? moment(s.period + "-01").format("MMM YYYY") : moment(s.period).format("MMM D")
        ),
        datasets: [
            {
                label: t("kpi.gross-sales"),
                data: (data?.series || []).map((s) => s.sales),
                backgroundColor: "rgba(103, 119, 239, 0.75)",
                borderRadius: 4,
            },
        ],
    };

    return (
        <MasterLayout>
            <TopProgressBar />
            <TabTitle title={t("kpi.title")} />
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-5">
                <div>
                    <h1 className="mb-1">{t("kpi.title")}</h1>
                    {data && (
                        <div className="text-gray-600">
                            {t("kpi.compared-with")} {data.previous_period.start} – {data.previous_period.end}
                        </div>
                    )}
                </div>
                <div className="d-flex gap-3 align-items-center">
                    <DateRangePicker onDateSelector={(date) => setRange(rangeFromPicker(date))} isProfitReport={true} />
                    <Button variant="primary" className="text-nowrap" onClick={() => setShowTargets(true)}>
                        {t("kpi.targets.set")}
                    </Button>
                </div>
            </div>

            {loading && !data ? (
                <Loading />
            ) : (
                <>
                    <Row className="g-4 mb-5">
                        <Col xs={12} sm={6} xl={3}>
                            <StatCard title={t("kpi.net-sales")} value={money(c.net_sales)} change={ch.net_sales} sub={`${t("kpi.returns")}: ${money(c.sales_returns)}`} />
                        </Col>
                        <Col xs={12} sm={6} xl={3}>
                            <StatCard title={t("kpi.gross-profit")} value={money(c.gross_profit)} change={ch.gross_profit} sub={`${t("kpi.margin")}: ${c.gross_margin}%`} />
                        </Col>
                        <Col xs={12} sm={6} xl={3}>
                            <StatCard title={t("kpi.net-profit")} value={money(c.net_profit)} change={ch.net_profit} sub={`${t("kpi.expenses")}: ${money(c.expenses)}`} />
                        </Col>
                        <Col xs={12} sm={6} xl={3}>
                            <StatCard title={t("kpi.transactions")} value={c.transactions ?? 0} change={ch.transactions} />
                        </Col>
                        <Col xs={12} sm={6} xl={3}>
                            <StatCard title={t("kpi.average-sale")} value={money(c.average_sale)} change={ch.average_sale} />
                        </Col>
                        <Col xs={12} sm={6} xl={3}>
                            <StatCard title={t("kpi.items-per-sale")} value={c.items_per_sale ?? 0} change={ch.items_per_sale} sub={`${t("kpi.items-sold")}: ${c.items_sold ?? 0}`} />
                        </Col>
                        <Col xs={12} sm={6} xl={3}>
                            <StatCard title={t("kpi.stock-value")} value={money(data?.inventory?.stock_value)} sub={data?.inventory?.days_of_stock != null ? t("kpi.days-of-stock", { days: data.inventory.days_of_stock }) : null} />
                        </Col>
                        <Col xs={12} sm={6} xl={3}>
                            <StatCard title={t("kpi.inventory-turnover")} value={data?.inventory?.turnover != null ? `${data.inventory.turnover}×` : "—"} sub={t("kpi.inventory-turnover.hint")} />
                        </Col>
                    </Row>

                    <Row className="g-4 mb-5">
                        <Col xl={7}>
                            <Card className="h-100 border-0 shadow-sm">
                                <Card.Header className="border-0 pt-5">
                                    <h3 className="mb-0">{t("kpi.sales-trend")}</h3>
                                </Card.Header>
                                <Card.Body>
                                    <div style={{ height: 300 }}>
                                        <Bar data={chartData} options={{ maintainAspectRatio: false, plugins: { legend: { display: false } } }} />
                                    </div>
                                </Card.Body>
                            </Card>
                        </Col>
                        <Col xl={5}>
                            <Card className="h-100 border-0 shadow-sm">
                                <Card.Header className="border-0 pt-5 d-flex justify-content-between align-items-center">
                                    <h3 className="mb-0">
                                        {t("kpi.targets.title")} · {moment(period + "-01").format("MMMM YYYY")}
                                    </h3>
                                </Card.Header>
                                <Card.Body>
                                    {(data?.targets || []).length === 0 ? (
                                        <div className="text-center text-gray-500 py-6">
                                            <div className="mb-3">{t("kpi.targets.none")}</div>
                                            <Button size="sm" variant="outline-primary" onClick={() => setShowTargets(true)}>
                                                {t("kpi.targets.set")}
                                            </Button>
                                        </div>
                                    ) : (
                                        data.targets.map((target) => <TargetRow key={target.id} target={target} money={money} t={t} />)
                                    )}
                                </Card.Body>
                            </Card>
                        </Col>
                    </Row>

                    <Row className="g-4">
                        {[
                            { key: "by_cashier", title: t("kpi.by-cashier") },
                            { key: "by_warehouse", title: t("kpi.by-warehouse") },
                        ].map((section) => (
                            <Col xl={6} key={section.key}>
                                <Card className="h-100 border-0 shadow-sm">
                                    <Card.Header className="border-0 pt-5">
                                        <h3 className="mb-0">{section.title}</h3>
                                    </Card.Header>
                                    <Card.Body className="pt-0">
                                        <Table responsive className="align-middle mb-0">
                                            <thead>
                                                <tr className="text-gray-600">
                                                    <th>{t("kpi.name")}</th>
                                                    <th className="text-end">{t("kpi.gross-sales")}</th>
                                                    <th className="text-end">{t("kpi.transactions")}</th>
                                                    <th className="text-end">{t("kpi.average-sale")}</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {(data?.[section.key] || []).length === 0 ? (
                                                    <EmptyRow colSpan={4} text={t("kpi.no-sales")} />
                                                ) : (
                                                    data[section.key].map((row) => (
                                                        <tr key={row.id ?? "none"}>
                                                            <td>{row.name}</td>
                                                            <td className="text-end">{money(row.sales)}</td>
                                                            <td className="text-end">{row.transactions}</td>
                                                            <td className="text-end">{money(row.average_sale)}</td>
                                                        </tr>
                                                    ))
                                                )}
                                            </tbody>
                                        </Table>
                                    </Card.Body>
                                </Card>
                            </Col>
                        ))}
                    </Row>
                </>
            )}

            <KpiTargetsModal
                show={showTargets}
                period={period}
                onHide={() => setShowTargets(false)}
                onSaved={load}
            />
        </MasterLayout>
    );
};

export default KpiDashboard;
