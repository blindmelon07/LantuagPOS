import { constants } from "../../constants";

export default (state = false, action) => {
    switch (action.type) {
        case constants.IS_SEARCH_LOADING:
            return action.payload;
        default:
            return state;
    }
}
