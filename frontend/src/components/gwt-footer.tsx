import Image from "next/image";

export function GWTFooter() {
  return (
    <div className="gwt-footer-wrap">
      <div className="gwt-footer">
        <div className="gwt-footer-row">
          <div className="gwt-footer-col gwt-footer-col-seal">
            <Image
              src="/images/govph-seal-mono-footer.png"
              alt="Republic of the Philippines Seal"
              className="gwt-footer-seal"
              width={80}
              height={80}
            />
          </div>
          <div className="gwt-footer-col gwt-footer-col-about">
            <h4>Republic of the Philippines</h4>
            <p className="gwt-footer-public-domain">
              All content is in the public domain unless otherwise stated.
            </p>
          </div>
          <div className="gwt-footer-col gwt-footer-col-links">
            <div className="gwt-footer-links-group">
              <h4>About GOVPH</h4>
              <p>
                Learn more about the Philippine government, its structure, how
                government works and the people behind it.
              </p>
              <ul>
                <li>
                  <a href="https://www.gov.ph/" target="_blank" rel="noopener">
                    GOV.PH
                  </a>
                </li>
                <li>
                  <a
                    href="https://www.gov.ph/data"
                    target="_blank"
                    rel="noopener"
                  >
                    Open Data Portal
                  </a>
                </li>
                <li>
                  <a
                    href="https://www.officialgazette.gov.ph"
                    target="_blank"
                    rel="noopener"
                  >
                    Official Gazette
                  </a>
                </li>
              </ul>
            </div>
          </div>
          <div className="gwt-footer-col gwt-footer-col-gov">
            <div className="gwt-footer-links-group">
              <h4>Government Links</h4>
              <ul>
                <li>
                  <a
                    href="https://president.gov.ph/"
                    target="_blank"
                    rel="noopener"
                  >
                    Office of the President
                  </a>
                </li>
                <li>
                  <a
                    href="https://ovp.gov.ph/"
                    target="_blank"
                    rel="noopener"
                  >
                    Office of the Vice President
                  </a>
                </li>
                <li>
                  <a
                    href="https://www.senate.gov.ph/"
                    target="_blank"
                    rel="noopener"
                  >
                    Senate of the Philippines
                  </a>
                </li>
                <li>
                  <a
                    href="https://www.congress.gov.ph/"
                    target="_blank"
                    rel="noopener"
                  >
                    House of Representatives
                  </a>
                </li>
                <li>
                  <a
                    href="https://sc.judiciary.gov.ph/"
                    target="_blank"
                    rel="noopener"
                  >
                    Supreme Court
                  </a>
                </li>
                <li>
                  <a
                    href="https://ca.judiciary.gov.ph/"
                    target="_blank"
                    rel="noopener"
                  >
                    Court of Appeals
                  </a>
                </li>
                <li>
                  <a
                    href="https://sb.judiciary.gov.ph/"
                    target="_blank"
                    rel="noopener"
                  >
                    Sandiganbayan
                  </a>
                </li>
              </ul>
            </div>
          </div>
        </div>
      </div>
    </div>
  );
}
