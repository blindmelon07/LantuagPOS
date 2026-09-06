import apiConfig from "../../../config/apiConfig";
import { useEffect, useState } from "react";
import { connect, useDispatch } from "react-redux";
import { FontAwesomeIcon } from "@fortawesome/react-fontawesome";
import SettingSidebar from "./SettingSidebar";
import TopProgressBar from "../../../shared/components/loaders/TopProgressBar";
import { getFormattedMessage, placeholderText } from "../../../shared/sharedMethod";
import { faInfoCircle } from "@fortawesome/free-solid-svg-icons";
import FiscalYearTable from "../../settings/FiscalYearTable";
import CreateFiscalYear from "../../settings/CreateFiscalYear";
import { fetchSetting } from "../../../store/action/settingAction";
import { fetchFiscalYear, updateFiscalYear } from "../../../store/action/FiscalYearAction";
import { addToast } from "../../../store/action/toastAction";
import MasterLayout from "../../MasterLayout";
import TabTitle from "../../../shared/tab-title/TabTitle";
import HeaderTitle from "../../header/HeaderTitle";
import AdminFiscalYearTable from "./AdminFiscalYearTable";
import AdminCreateFiscalYear from "./AdminCreateFiscalYear";

const AdminFiscalYearSettings = ({
    fetchSetting,
    fetchFiscalYear,
    updateFiscalYear,
    fiscalYears,
    settings,
    allConfigData,
    isLoading,
    totalRecord,
    addToast,
}) => {
    const dispatch = useDispatch();
    const [fiscalYearEnabled, setFiscalYearEnabled] = useState(false);

    useEffect(() => {
        fetchSetting();
        fetchFiscalYear();
    }, []);

    useEffect(() => {
        setFiscalYearEnabled(parseInt(settings?.attributes?.enable_fiscal_year_filter));
    }, [settings]);

    const handleActivate = (e, id) => {
        e.preventDefault();
        updateFiscalYear({ id: id, is_active: true });
    };

    const ToggleFiscalYear = async (value) => {
        if (fiscalYears.length == 0) {
            return dispatch(
                addToast({
                    text: getFormattedMessage("fiscal-year.message.please-add-fiscal-year"),
                    type: toastType.ERROR,
                })
            );
        }
        await apiConfig
            .post("fiscal-year/filter-setting", { enable_fiscal_year_filter: value })
            .then((response) => {
                dispatch(
                    addToast({
                        text: getFormattedMessage("fiscal-year.message.fiscal-year-setting-updated"),
                    })
                );
                setFiscalYearEnabled(value);
                fetchSetting();
            })
            .catch(({ response }) => {
                dispatch(
                    addToast({
                        text: response.data.message,
                        type: toastType.ERROR,
                    })
                );
            });
    };

    const onChange = (filter) => {
        fetchFiscalYear(filter);
    };

    return (
        <MasterLayout>
            <TopProgressBar />
            <TabTitle title={placeholderText("fiscal-year.title")} />
            <div className="card">
                <div className="card-body">
                    <div className="d-md-flex gap-3">
                        <SettingSidebar />
                        <div className="flex-grow-1">
                            <div className="card mb-10">
                                <div className="card-body py-0 px-0">
                                    <div className="row px-0">
                                        <div className="d-flex gap-3 p-2 align-items-center bg-light border-2 border-light rounded-2">
                                            <FontAwesomeIcon icon={faInfoCircle} className="fs-1" />
                                            <p className="m-0 w-lg-75">{getFormattedMessage("fiscal-year.instruction")}</p>
                                        </div>
                                    </div>
                                    <div className="row px-0 mt-5">
                                        <div className="col-lg-3 sm-col-6 d-flex align-items-center">
                                            <label className="form-check form-switch form-switch-lg flex-grow-1">
                                                <input
                                                    type="checkbox"
                                                    checked={fiscalYearEnabled}
                                                    name="enable_nepali_datepicker"
                                                    onChange={(event) => ToggleFiscalYear(event.target.checked)}
                                                    className="me-3 form-check-input cursor-pointer"
                                                />
                                                <div className="control__indicator" />{" "}
                                                {getFormattedMessage("fiscal-year.enable.title")}
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <AdminFiscalYearTable
                                fiscalYears={fiscalYears}
                                allConfigData={allConfigData}
                                handleActivate={handleActivate}
                                isLoading={isLoading}
                                AddButton={<AdminCreateFiscalYear />}
                                onChange={onChange}
                                totalRecord={totalRecord}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </MasterLayout>
    );
};

const mapStateToProps = (state) => {
    const { settings, fiscalYears, allConfigData, isLoading, totalRecord } = state;
    return { settings, fiscalYears, allConfigData, isLoading, totalRecord };
};

export default connect(mapStateToProps, {
    fetchSetting,
    fetchFiscalYear,
    updateFiscalYear,
    addToast,
})(AdminFiscalYearSettings);